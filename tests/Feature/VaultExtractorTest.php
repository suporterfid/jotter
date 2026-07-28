<?php

namespace Tests\Feature;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Vault\VaultExtractor;
use App\Domain\Vault\VaultPathGuard;
use App\Models\Tenant;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

final class VaultExtractorTest extends TestCase
{
    use RefreshDatabase;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir().'/jotter-extractor-'.uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->tempDir);
        parent::tearDown();
    }

    public function test_vault_extractor_extracts_allowed_files_and_rejects_zip_slip(): void
    {
        $tenant = Tenant::create(['slug' => 'test', 'name' => 'Test']);
        $vaultDir = $this->tempDir.'/vault';
        mkdir($vaultDir, 0755, true);

        $workspace = Workspace::create([
            'tenant_id' => $tenant->id,
            'slug' => 'main',
            'name' => 'Main',
            'vault_path' => $vaultDir,
        ]);

        $zipPath = $this->tempDir.'/test.zip';
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE);
        $zip->addFromString('index.md', '# Index Note');
        $zip->addFromString('docs/guide.md', '# Guide');
        $zip->addFromString('../outside.md', '# Outside Vault');
        $zip->addFromString('malicious.php', '<?php echo "evil";');
        $zip->close();

        $pathGuard = new VaultPathGuard;
        $auditRecorder = $this->app->make(AuditRecorder::class);
        $extractor = new VaultExtractor($pathGuard, $auditRecorder);

        $result = $extractor->extract($workspace, $zipPath);

        $this->assertCount(2, $result['extracted']);
        $this->assertGreaterThanOrEqual(2, count($result['skipped']));
        $this->assertFileExists($vaultDir.'/index.md');
        $this->assertFileExists($vaultDir.'/docs/guide.md');
        $this->assertFileDoesNotExist($vaultDir.'/../outside.md');
        $this->assertFileDoesNotExist($vaultDir.'/malicious.php');
    }

    private function deleteTree(string $path): void
    {
        if (! is_dir($path)) return;
        foreach (scandir($path) as $item) {
            if ($item === '.' || $item === '..') continue;
            $sub = $path.'/'.$item;
            is_dir($sub) ? $this->deleteTree($sub) : unlink($sub);
        }
        rmdir($path);
    }
}
