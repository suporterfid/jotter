<?php

namespace Tests\Feature;

use App\Domain\Vault\VaultStorage;
use App\Models\Note;
use App\Models\NoteLink;
use App\Models\Tenant;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class WikilinkProjectionTest extends TestCase
{
    use RefreshDatabase;

    private string $vaultRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vaultRoot = sys_get_temp_dir().'/jotter-links-'.uniqid('', true);
        mkdir($this->vaultRoot, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->vaultRoot);

        parent::tearDown();
    }

    public function test_wikilinks_are_projected_and_backlinks_are_a_database_query(): void
    {
        $workspace = $this->makeWorkspace();
        $storage = new VaultStorage;

        $target = $storage->write($workspace, 'B.md', "# B\n");
        $source = $storage->write($workspace, 'A.md', "[[B]] [[B|display label]] [[B#heading]]\n");

        $this->assertSame(3, $source->outgoingLinks()->where('type', 'wikilink')->count());
        $this->assertSame(3, $target->incomingLinks()->where('type', 'wikilink')->count());
        $this->assertSame(
            [$source->id],
            $target->incomingLinks()->where('type', 'wikilink')->pluck('source_note_id')->unique()->all(),
        );
    }

    public function test_missing_wikilinks_are_stored_unresolved_without_error(): void
    {
        $workspace = $this->makeWorkspace();

        $source = (new VaultStorage)->write($workspace, 'A.md', "[[C]]\n");

        $link = NoteLink::query()->where('source_note_id', $source->id)->sole();
        $this->assertSame('C', $link->target_ref);
        $this->assertSame('wikilink', $link->type);
        $this->assertNull($link->target_note_id);
    }

    public function test_reindex_resolves_a_previously_unresolved_link_after_its_note_is_created(): void
    {
        $workspace = $this->makeWorkspace();
        $storage = new VaultStorage;

        $source = $storage->write($workspace, 'A.md', "[[Later]]\n");
        $this->assertNull($source->outgoingLinks()->sole()->target_note_id);

        file_put_contents($this->vaultRoot.'/Later.md', "# Later\n");

        $exit = Artisan::call('vault:reindex', [
            '--workspace' => (string) $workspace->id,
            '--batch' => '1',
        ]);

        $this->assertSame(0, $exit);
        $target = Note::query()->where('workspace_id', $workspace->id)->where('path', 'Later.md')->sole();
        $this->assertSame($target->id, $source->outgoingLinks()->sole()->fresh()->target_note_id);
    }

    public function test_wikilink_resolves_via_frontmatter_alias(): void
    {
        $workspace = $this->makeWorkspace();
        $storage = new VaultStorage;

        $target = $storage->write($workspace, 'research.md', "---\naliases: [research, study]\n---\n# Research\n");
        $source = $storage->write($workspace, 'current.md', "See [[research]] and [[study]].\n");

        $links = $source->outgoingLinks()->where('type', 'wikilink')->get();
        $this->assertCount(2, $links);
        $this->assertSame([$target->id, $target->id], $links->pluck('target_note_id')->all());

        $this->assertSame(
            [$source->id],
            $target->incomingLinks()->where('type', 'wikilink')->pluck('source_note_id')->unique()->all(),
        );
    }

    public function test_alias_resolution_accepts_comma_separated_string_form(): void
    {
        $workspace = $this->makeWorkspace();
        $storage = new VaultStorage;

        $target = $storage->write($workspace, 'glossary.md', "---\naliases: \"Glossary, Terms\"\n---\n# Glossary\n");
        $source = $storage->write($workspace, 'current.md', "[[Terms]]\n");

        $this->assertSame($target->id, $source->outgoingLinks()->sole()->target_note_id);
    }

    public function test_block_reference_is_persisted_and_queryable_via_backlinks(): void
    {
        $workspace = $this->makeWorkspace();
        $storage = new VaultStorage;

        $target = $storage->write($workspace, 'note.md', "Paragraph text\n^myblock\n\nAnother paragraph.\n");
        $source = $storage->write($workspace, 'current.md', "See [[note#^myblock]] for details.\n");

        $link = $source->outgoingLinks()->where('type', 'wikilink')->sole();
        $this->assertSame('myblock', $link->target_block);
        $this->assertSame($target->id, $link->target_note_id);

        $blockBacklinks = $target->incomingBlockReferences('myblock');
        $this->assertSame([$source->id], $blockBacklinks->pluck('source_note_id')->all());

        $this->assertSame(0, $target->incomingBlockReferences('other-block')->count());
    }

    public function test_heading_fragment_is_not_treated_as_a_block_reference(): void
    {
        $workspace = $this->makeWorkspace();
        $storage = new VaultStorage;

        $target = $storage->write($workspace, 'note.md', "# Heading\n\nBody.\n");
        $source = $storage->write($workspace, 'current.md', "[[note#Heading]]\n");

        $link = $source->outgoingLinks()->where('type', 'wikilink')->sole();
        $this->assertNull($link->target_block);
        $this->assertSame($target->id, $link->target_note_id);
    }

    public function test_rename_rewrites_inbound_wikilinks_on_disk(): void
    {
        $workspace = $this->makeWorkspace();
        $storage = new VaultStorage;

        $storage->write($workspace, 'note-b.md', "# Note B\n");
        $storage->write($workspace, 'note-a.md', "See [[note-b]] and [[Note B|display label]] for details.\n");

        $storage->move($workspace, 'note-b.md', 'note-c.md');

        // Verify on disk, not the API/DB -- the index can be correct while disk is
        // wrong, and disk is what Obsidian reads over WebDAV.
        $onDisk = file_get_contents($this->vaultRoot.'/note-a.md');
        $this->assertStringNotContainsString('[[note-b]]', $onDisk);
        $this->assertStringContainsString('[[note-c]]', $onDisk);
        $this->assertStringContainsString('[[note-c|display label]]', $onDisk);

        $source = Note::query()->where('workspace_id', $workspace->id)->where('path', 'note-a.md')->sole();
        $target = Note::query()->where('workspace_id', $workspace->id)->where('path', 'note-c.md')->sole();
        $this->assertSame(
            [$target->id, $target->id],
            $source->outgoingLinks()->where('type', 'wikilink')->orderBy('id')->pluck('target_note_id')->all(),
        );
    }

    public function test_rename_does_not_rewrite_links_to_unrelated_notes(): void
    {
        $workspace = $this->makeWorkspace();
        $storage = new VaultStorage;

        $storage->write($workspace, 'note-b.md', "# Note B\n");
        $storage->write($workspace, 'note-d.md', "# Note D\n");
        $storage->write($workspace, 'note-a.md', "[[note-b]] and [[note-d]]\n");

        $storage->move($workspace, 'note-b.md', 'note-c.md');

        $onDisk = file_get_contents($this->vaultRoot.'/note-a.md');
        $this->assertStringContainsString('[[note-d]]', $onDisk);
    }

    private function makeWorkspace(): Workspace
    {
        $tenant = Tenant::query()->create([
            'slug' => 'links-tenant-'.uniqid(),
            'name' => 'Links Tenant',
        ]);

        return Workspace::query()->create([
            'tenant_id' => $tenant->id,
            'slug' => 'links-ws-'.uniqid(),
            'name' => 'Links Workspace',
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
