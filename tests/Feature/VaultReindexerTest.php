<?php

namespace Tests\Feature;

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
}
