<?php

namespace Tests\Feature;

use App\Domain\Vault\NoteTrash;
use App\Domain\Vault\VaultStorage;
use App\Domain\Vault\VaultReindexer;
use App\Models\Note;
use App\Models\Tenant;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class VaultReindexerTest extends TestCase
{
    use RefreshDatabase;

    public function test_reindex_excludes_excalidraw_md_files_from_notes(): void
    {
        $tenant = Tenant::create(['slug' => 'test', 'name' => 'Test']);

        $vaultPath = storage_path('app/vaults/excalidraw_test_'.uniqid());
        mkdir($vaultPath, 0755, true);

        $workspace = Workspace::create([
            'tenant_id' => $tenant->id,
            'slug' => 'main',
            'name' => 'Main Workspace',
            'vault_path' => $vaultPath,
        ]);

        file_put_contents($vaultPath.'/regular.md', "# Regular note\n\nBody text.");
        file_put_contents($vaultPath.'/diagram.excalidraw.md', json_encode(['type' => 'excalidraw', 'elements' => []]));

        /** @var VaultReindexer $reindexer */
        $reindexer = $this->app->make(VaultReindexer::class);
        $result = $reindexer->reindex($workspace);

        $this->assertSame(1, $result['scanned']);
        $this->assertSame(1, $result['upserted']);
        $this->assertDatabaseHas('notes', [
            'workspace_id' => $workspace->id,
            'path' => 'regular.md',
        ]);
        $this->assertDatabaseMissing('notes', [
            'workspace_id' => $workspace->id,
            'path' => 'diagram.excalidraw.md',
        ]);
        $this->assertSame(1, Note::query()->where('workspace_id', $workspace->id)->count());

        // The file itself must be preserved on disk, never deleted by reindex.
        $this->assertFileExists($vaultPath.'/diagram.excalidraw.md');
    }

    public function test_reindex_does_not_resurrect_a_trashed_note(): void
    {
        $tenant = Tenant::create(['slug' => 'trash-reindex-'.uniqid(), 'name' => 'Trash Reindex']);
        $vaultPath = storage_path('app/vaults/trash_reindex_'.uniqid());
        mkdir($vaultPath, 0755, true);

        try {
            $workspace = Workspace::create([
                'tenant_id' => $tenant->id,
                'slug' => 'trash-reindex',
                'name' => 'Trash Reindex',
                'vault_path' => $vaultPath,
            ]);
            $note = app(VaultStorage::class)->write($workspace, 'deleted.md', "# Deleted\n");
            $trashed = app(NoteTrash::class)->trash($workspace, $note);

            app(VaultReindexer::class)->reindex($workspace);

            $this->assertTrue($trashed->fresh()->trashed());
            $this->assertDatabaseCount('notes', 1);
            $this->assertDatabaseMissing('notes', [
                'workspace_id' => $workspace->id,
                'path' => $trashed->path,
                'deleted_at' => null,
            ]);
        } finally {
            $this->deleteTree($vaultPath);
        }
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
