<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;
use ZipArchive;

final class WorkspaceImportApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_import_endpoint_extracts_archive_and_reindexes(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $tenant = Tenant::create(['slug' => 'default', 'name' => 'Default']);
        $vaultPath = storage_path('app/vaults/import_api_test_'.uniqid());
        @mkdir($vaultPath, 0755, true);

        $workspace = Workspace::create([
            'tenant_id' => $tenant->id,
            'slug' => 'main',
            'name' => 'Main',
            'vault_path' => $vaultPath,
        ]);

        $zipPath = sys_get_temp_dir().'/api_import_'.uniqid().'.zip';
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('imported_note.md', "# Imported Note\n");
        $zip->close();

        $file = new UploadedFile($zipPath, 'archive.zip', 'application/zip', null, true);

        $response = $this->actingAs($admin)
            ->postJson("/api/workspaces/{$workspace->id}/import", [
                'archive' => $file,
            ]);

        $response->assertOk()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('extracted_count', 1);

        $this->assertFileExists("{$vaultPath}/imported_note.md");
        $this->assertDatabaseHas('notes', [
            'workspace_id' => $workspace->id,
            'path' => 'imported_note.md',
        ]);

        @unlink($zipPath);
    }
}
