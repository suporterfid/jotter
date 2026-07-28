<?php

namespace Tests\Feature;

use App\Domain\Vault\VaultExtractor;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

final class VaultExtractorTest extends TestCase
{
    use RefreshDatabase;

    public function test_vault_extractor_extracts_allowed_files_and_rejects_zip_slip(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $tenant = Tenant::create(['slug' => 'default', 'name' => 'Default']);
        $vaultPath = storage_path('app/vaults/extractor_test');
        @mkdir($vaultPath, 0755, true);

        $workspace = Workspace::create([
            'tenant_id' => $tenant->id,
            'slug' => 'main',
            'name' => 'Main',
            'vault_path' => $vaultPath,
        ]);

        $zipPath = sys_get_temp_dir().'/test_archive_'.uniqid().'.zip';
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('valid_note.md', "# Valid Note\n");
        $zip->addFromString('../../etc/passwd', 'malicious');
        $zip->addFromString('script.sh', '#!/bin/bash');
        $zip->close();

        $extractor = new VaultExtractor();
        $result = $extractor->extract($workspace, $zipPath);

        $this->assertContains('valid_note.md', $result['extracted']);
        $this->assertNotEmpty($result['errors']);
        $this->assertNotEmpty($result['skipped']);
        $this->assertFileExists("{$vaultPath}/valid_note.md");

        @unlink($zipPath);
    }
}
