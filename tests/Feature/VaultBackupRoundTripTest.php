<?php

namespace Tests\Feature;

use App\Domain\Vault\VaultStorage;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

final class VaultBackupRoundTripTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_import_round_trip_equivalence_and_collision_policy(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $tenant = Tenant::create(['slug' => 'default', 'name' => 'Default']);

        $sourceVault = storage_path('app/vaults/round_trip_source_'.uniqid());
        $targetVault = storage_path('app/vaults/round_trip_target_'.uniqid());
        @mkdir($sourceVault, 0755, true);
        @mkdir($targetVault, 0755, true);

        $sourceWs = Workspace::create([
            'tenant_id' => $tenant->id,
            'slug' => 'source',
            'name' => 'Source',
            'vault_path' => $sourceVault,
        ]);

        $targetWs = Workspace::create([
            'tenant_id' => $tenant->id,
            'slug' => 'target',
            'name' => 'Target',
            'vault_path' => $targetVault,
        ]);

        $storage = app(VaultStorage::class);
        $storage->write($sourceWs, 'target_note.md', "# Target Note\n");
        $storage->write($sourceWs, 'source_note.md', "# Source Note\n\nLink to [[target_note]].\n");

        $this->assertCount(2, $sourceWs->notes()->get());

        // 1. Export source workspace
        $exportRes = $this->actingAs($admin)->get("/api/workspaces/{$sourceWs->id}/export");
        $exportRes->assertOk();

        $zipPath = storage_path("app/private/export_workspace_{$sourceWs->id}.zip");
        $this->assertFileExists($zipPath);

        $testZip = new \ZipArchive();
        $testZip->open($zipPath);
        $numFiles = $testZip->numFiles;
        $testZip->close();

        $this->assertEquals(2, $numFiles, "Exported ZIP contains {$numFiles} files.");

        $tempCopy1 = sys_get_temp_dir().'/roundtrip_copy1_'.uniqid().'.zip';
        $tempCopy2 = sys_get_temp_dir().'/roundtrip_copy2_'.uniqid().'.zip';
        copy($zipPath, $tempCopy1);
        copy($zipPath, $tempCopy2);

        // 2. Import into target workspace
        $file = new UploadedFile($tempCopy1, 'backup.zip', 'application/zip', null, true);
        $importRes = $this->actingAs($admin)->postJson("/api/workspaces/{$targetWs->id}/import", [
            'archive' => $file,
        ]);
        $importRes->assertOk()->assertJsonPath('extracted_count', 2);

        $this->assertFileExists("{$targetVault}/source_note.md");
        $this->assertFileExists("{$targetVault}/target_note.md");

        // Assert backlinks re-projected identically
        $targetNote = $targetWs->notes()->where('path', 'target_note.md')->first();
        $this->assertNotNull($targetNote);
        $this->assertCount(1, $targetNote->incomingLinks);

        // 3. Test collision policy skip by default
        $file2 = new UploadedFile($tempCopy2, 'backup2.zip', 'application/zip', null, true);
        $skipRes = $this->actingAs($admin)->postJson("/api/workspaces/{$targetWs->id}/import", [
            'archive' => $file2,
            'overwrite' => false,
        ]);
        $skipRes->assertOk()
            ->assertJsonPath('extracted_count', 0)
            ->assertJsonPath('skipped_count', 2);

        @unlink($tempCopy1);
        @unlink($tempCopy2);
    }
}
