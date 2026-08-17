<?php

namespace Tests\Feature;

use App\Domain\Vault\NoteTrash;
use App\Domain\Vault\VaultStorage;
use App\Models\Note;
use App\Models\Tag;
use App\Models\Tenant;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class NoteTrashTest extends TestCase
{
    use RefreshDatabase;

    private string $vaultRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vaultRoot = sys_get_temp_dir().'/jotter-trash-'.uniqid('', true);
        mkdir($this->vaultRoot, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->vaultRoot);

        parent::tearDown();
    }

    public function test_soft_deleted_notes_are_hidden_from_active_queries(): void
    {
        $workspace = $this->makeWorkspace();
        $note = Note::query()->create([
            'workspace_id' => $workspace->id,
            'path' => 'readme.md',
            'title' => 'Readme',
            'content_hash' => hash('sha256', "# Readme\n"),
            'search_content' => 'Readme',
        ]);

        $note->delete();

        $this->assertSoftDeleted('notes', ['id' => $note->id]);
        $this->assertFalse(Note::query()->whereKey($note->id)->exists());
        $this->assertTrue(Note::withTrashed()->whereKey($note->id)->exists());
        $this->assertTrue(Note::withTrashed()->findOrFail($note->id)->trashed());
    }

    public function test_trash_moves_the_file_and_restore_reprojects_the_note(): void
    {
        $workspace = $this->makeWorkspace();
        $contents = "---\ntags: [guide]\n---\nBody\n";
        $note = app(VaultStorage::class)->write($workspace, 'docs/readme.md', $contents);

        $trashed = app(NoteTrash::class)->trash($workspace, $note);

        $this->assertTrue($trashed->trashed());
        $this->assertFileDoesNotExist($this->vaultRoot.'/docs/readme.md');
        $this->assertFileExists($this->vaultRoot.'/'.$trashed->path);
        $this->assertSame('docs/readme.md', $trashed->original_path);
        $this->assertFalse(Note::query()->whereKey($note->id)->exists());

        $restored = app(NoteTrash::class)->restore($workspace, $trashed->fresh(['workspace']));

        $this->assertFalse($restored->trashed());
        $this->assertSame($note->id, $restored->id);
        $this->assertSame('docs/readme.md', $restored->path);
        $this->assertNull($restored->original_path);
        $this->assertSame($contents, file_get_contents($this->vaultRoot.'/docs/readme.md'));
        $this->assertTrue(Tag::query()->where('workspace_id', $workspace->id)->where('name', 'guide')->exists());
    }

    public function test_restore_rejects_an_occupied_original_path_and_keeps_the_note_trashed(): void
    {
        $workspace = $this->makeWorkspace();
        $storage = app(VaultStorage::class);
        $note = $storage->write($workspace, 'same.md', "# First\n");
        $trashed = app(NoteTrash::class)->trash($workspace, $note);
        $storage->write($workspace, 'same.md', "# Replacement\n");

        try {
            app(NoteTrash::class)->restore($workspace, $trashed->fresh(['workspace']));
            $this->fail('Expected restore to reject the occupied original path.');
        } catch (\App\Domain\Vault\Exceptions\TrashRestoreConflict) {
            $this->assertTrue($trashed->fresh()->trashed());
            $this->assertFileExists($this->vaultRoot.'/'.$trashed->path);
            $this->assertSame("# Replacement\n", file_get_contents($this->vaultRoot.'/same.md'));
        }
    }

    public function test_permanent_delete_removes_the_trashed_file_and_row(): void
    {
        $workspace = $this->makeWorkspace();
        $note = app(VaultStorage::class)->write($workspace, 'permanent.md', "# Permanent\n");
        $trashed = app(NoteTrash::class)->trash($workspace, $note);
        $trashPath = $this->vaultRoot.'/'.$trashed->path;

        app(NoteTrash::class)->permanentlyDelete($workspace, $trashed->fresh(['workspace']));

        $this->assertFileDoesNotExist($trashPath);
        $this->assertDatabaseMissing('notes', ['id' => $note->id]);
    }

    private function makeWorkspace(): Workspace
    {
        $tenant = Tenant::query()->create([
            'slug' => 'trash-tenant-'.uniqid(),
            'name' => 'Trash Tenant',
        ]);

        return Workspace::query()->create([
            'tenant_id' => $tenant->id,
            'slug' => 'trash-workspace-'.uniqid(),
            'name' => 'Trash Workspace',
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

        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $this->deleteTree($path.DIRECTORY_SEPARATOR.$item);
        }

        @rmdir($path);
    }
}
