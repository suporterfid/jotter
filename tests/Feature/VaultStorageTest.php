<?php

namespace Tests\Feature;

use App\Domain\Vault\Exceptions\PathTraversalRejected;
use App\Domain\Vault\MarkdownDocument;
use App\Domain\Vault\VaultPathGuard;
use App\Domain\Vault\VaultStorage;
use App\Models\AuditLog;
use App\Models\Note;
use App\Models\Tag;
use App\Models\Tenant;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class VaultStorageTest extends TestCase
{
    use RefreshDatabase;

    private string $vaultRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vaultRoot = sys_get_temp_dir().'/jotter-vault-'.uniqid('', true);
        mkdir($this->vaultRoot, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->vaultRoot);

        parent::tearDown();
    }

    public function test_path_traversal_is_rejected_before_filesystem_access_and_audited(): void
    {
        $workspace = $this->makeWorkspace();
        $storage = new VaultStorage;

        $outsideDir = sys_get_temp_dir().'/jotter-outside-'.uniqid('', true);
        mkdir($outsideDir, 0755, true);
        $sentinel = $outsideDir.'/sentinel.md';
        file_put_contents($sentinel, "safe\n");
        $before = file_get_contents($sentinel);
        $mtimeBefore = filemtime($sentinel);

        // Spec §7.1 acceptance example (no .md suffix).
        try {
            $storage->write($workspace, '../../etc/passwd', "pwned\n");
            $this->fail('Expected path traversal to be rejected.');
        } catch (PathTraversalRejected $exception) {
            $this->assertSame($workspace->id, $exception->workspaceId);
            $this->assertSame('../../etc/passwd', $exception->attemptedPath);
        }

        $this->assertSame($before, file_get_contents($sentinel));
        $this->assertSame($mtimeBefore, filemtime($sentinel));

        $audit = AuditLog::query()->where('event', VaultPathGuard::AUDIT_EVENT)->sole();
        $this->assertSame($workspace->tenant_id, $audit->tenant_id);
        $this->assertSame($workspace->id, $audit->workspace_id);
        $this->assertSame('../../etc/passwd', $audit->metadata['attempted_path']);

        try {
            $storage->read($workspace, '/etc/passwd.md');
            $this->fail('Expected absolute path to be rejected.');
        } catch (PathTraversalRejected) {
            // expected
        }

        try {
            $storage->write($workspace, 'notes/../../outside.md', "nope\n");
            $this->fail('Expected nested traversal to be rejected.');
        } catch (PathTraversalRejected) {
            // expected
        }

        $this->assertSame(3, AuditLog::query()->where('event', VaultPathGuard::AUDIT_EVENT)->count());

        $this->deleteTree($outsideDir);
    }

    public function test_front_matter_is_parsed_into_the_projection(): void
    {
        $workspace = $this->makeWorkspace();
        $storage = new VaultStorage;

        $raw = <<<'MD'
---
title: Parsed Title
tags:
  - alpha
  - beta
status: draft
---
Body for search projection.
MD;

        $note = $storage->write($workspace, 'inbox/parsed.md', $raw);

        $this->assertSame('inbox/parsed.md', $note->path);
        $this->assertSame('Parsed Title', $note->title);
        $this->assertSame('draft', $note->frontmatter['status']);
        $this->assertSame(['alpha', 'beta'], $note->frontmatter['tags']);
        $this->assertSame(hash('sha256', $raw), $note->content_hash);
        $this->assertSame('Body for search projection.', $note->search_content);
        $this->assertEqualsCanonicalizing(['alpha', 'beta'], $note->tags()->pluck('name')->all());
        $this->assertFileExists($this->vaultRoot.'/inbox/parsed.md');
        $this->assertSame($raw, file_get_contents($this->vaultRoot.'/inbox/parsed.md'));
    }

    public function test_write_incrementally_updates_title_front_matter_and_tags(): void
    {
        $workspace = $this->makeWorkspace();
        $storage = new VaultStorage;

        $storage->writeDocument($workspace, 'daily.md', [
            'title' => 'Day One',
            'tags' => ['one'],
        ], "First body\n");

        $updated = $storage->writeDocument($workspace, 'daily.md', [
            'title' => 'Day Two',
            'tags' => ['two', 'shared'],
        ], "Second body\n");

        $this->assertSame(1, Note::query()->count());
        $this->assertSame('Day Two', $updated->title);
        $this->assertSame(['two', 'shared'], $updated->frontmatter['tags']);
        $this->assertSame('Second body', $updated->search_content);
        $this->assertEqualsCanonicalizing(['two', 'shared'], $updated->tags()->pluck('name')->all());
        $this->assertTrue(Tag::query()->where('name', 'one')->exists());
    }

    public function test_vault_reindex_picks_up_out_of_band_disk_edits(): void
    {
        $workspace = $this->makeWorkspace();
        $storage = new VaultStorage;

        $storage->writeDocument($workspace, 'oob.md', [
            'title' => 'Before',
            'tags' => ['old'],
        ], "Original\n");

        $oob = <<<'MD'
---
title: After OOB Edit
tags:
  - fresh
---
Edited directly on disk.
MD;
        file_put_contents($this->vaultRoot.'/oob.md', $oob);

        $exit = Artisan::call('vault:reindex', [
            '--workspace' => (string) $workspace->id,
            '--batch' => '10',
        ]);

        $this->assertSame(0, $exit);

        $note = Note::query()->where('path', 'oob.md')->sole();
        $this->assertSame('After OOB Edit', $note->title);
        $this->assertSame(['fresh'], $note->frontmatter['tags']);
        $this->assertSame('Edited directly on disk.', $note->search_content);
        $this->assertEqualsCanonicalizing(['fresh'], $note->tags()->pluck('name')->all());
        $this->assertSame(hash('sha256', $oob), $note->content_hash);
    }

    public function test_nested_folder_paths_are_allowed_with_root_validation(): void
    {
        $workspace = $this->makeWorkspace();
        $storage = new VaultStorage;

        $note = $storage->write($workspace, 'projects/alpha/readme.md', "# Nested\n");

        $this->assertSame('projects/alpha/readme.md', $note->path);
        $this->assertSame('Nested', $note->title);
        $this->assertFileExists($this->vaultRoot.'/projects/alpha/readme.md');
    }

    public function test_reindex_removes_stale_note_rows_missing_on_disk(): void
    {
        $workspace = $this->makeWorkspace();
        $storage = new VaultStorage;

        $storage->write($workspace, 'keep.md', "# Keep\n");
        $storage->write($workspace, 'gone.md', "# Gone\n");
        unlink($this->vaultRoot.'/gone.md');

        Artisan::call('vault:reindex', ['--workspace' => (string) $workspace->id]);

        $this->assertTrue(Note::query()->where('path', 'keep.md')->exists());
        $this->assertFalse(Note::query()->where('path', 'gone.md')->exists());
    }

    public function test_markdown_document_parse_round_trip_helpers(): void
    {
        $composed = MarkdownDocument::compose([
            'title' => 'Compose',
            'tags' => ['x'],
        ], "Hello\n");

        $parsed = MarkdownDocument::parse($composed, 'fallback');

        $this->assertSame('Compose', $parsed->title);
        $this->assertSame(['x'], $parsed->tags);
        $this->assertSame('Hello', $parsed->searchContent);
    }

    private function makeWorkspace(): Workspace
    {
        $tenant = Tenant::query()->create([
            'slug' => 'vault-tenant-'.uniqid(),
            'name' => 'Vault Tenant',
        ]);

        return Workspace::query()->create([
            'tenant_id' => $tenant->id,
            'slug' => 'vault-ws-'.uniqid(),
            'name' => 'Vault Workspace',
            'vault_path' => $this->vaultRoot,
        ]);
    }

    private function deleteTree(string $path): void
    {
        if (! is_dir($path) && ! is_file($path)) {
            return;
        }

        if (is_file($path)) {
            @unlink($path);

            return;
        }

        $items = scandir($path) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $this->deleteTree($path.DIRECTORY_SEPARATOR.$item);
        }

        @rmdir($path);
    }
}
