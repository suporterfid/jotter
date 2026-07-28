<?php

namespace Tests\Feature;

use App\Domain\Vault\VaultExtractor;
use App\Domain\Vault\VaultReindexer;
use App\Models\Note;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

final class VaultBackupRoundTripTest extends TestCase
{
    use RefreshDatabase;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir().'/jotter-roundtrip-'.uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->tempDir);
        parent::tearDown();
    }

    public function test_export_import_round_trip_equivalence_and_collision_policy(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $tenant = Tenant::create(['slug' => 'test', 'name' => 'Test']);

        $vaultA = $this->tempDir.'/vaultA';
        $vaultB = $this->tempDir.'/vaultB';
        mkdir($vaultA, 0755, true);
        mkdir($vaultB, 0755, true);

        $wsA = Workspace::create(['tenant_id' => $tenant->id, 'slug' => 'ws-a', 'name' => 'Workspace A', 'vault_path' => $vaultA]);
        $wsB = Workspace::create(['tenant_id' => $tenant->id, 'slug' => 'ws-b', 'name' => 'Workspace B', 'vault_path' => $vaultB]);

        file_put_contents($vaultA.'/welcome.md', "---\ntitle: Welcome Note\ntags:\n  - guide\n---\n# Welcome\n\nLink to [[docs/help.md|Help]]");
        mkdir($vaultA.'/docs', 0755, true);
        file_put_contents($vaultA.'/docs/help.md', "---\ntitle: Help Doc\ntags:\n  - guide\n---\n# Help Page");

        /** @var VaultReindexer $reindexer */
        $reindexer = $this->app->make(VaultReindexer::class);
        $reindexer->reindex($wsA);

        $this->assertDatabaseHas('notes', ['workspace_id' => $wsA->id, 'path' => 'welcome.md']);
        $this->assertDatabaseHas('notes', ['workspace_id' => $wsA->id, 'path' => 'docs/help.md']);

        // Test Export WS A as JSON
        $jsonResponse = $this->actingAs($admin)->getJson("/api/workspaces/{$wsA->id}/export?format=json");
        $jsonResponse->assertOk()
            ->assertJsonPath('version', '1.0')
            ->assertJsonCount(2, 'notes');

        $jsonFile = $this->tempDir.'/backup.json';
        file_put_contents($jsonFile, $jsonResponse->getContent());

        // Import JSON backup into empty Workspace B
        /** @var VaultExtractor $extractor */
        $extractor = $this->app->make(VaultExtractor::class);
        $result = $extractor->extract($wsB, $jsonFile);
        $reindexer->reindex($wsB);

        $this->assertCount(2, $result['extracted']);
        $this->assertFileExists($vaultB.'/welcome.md');
        $this->assertFileExists($vaultB.'/docs/help.md');

        $noteBWelcome = Note::where('workspace_id', $wsB->id)->where('path', 'welcome.md')->first();
        $this->assertNotNull($noteBWelcome);
        $this->assertEquals('Welcome Note', $noteBWelcome->title);

        // Test Collision Policy (skip when overwrite = false)
        file_put_contents($vaultB.'/welcome.md', "# Modified Content");
        $resultCollision = $extractor->extract($wsB, $jsonFile, overwrite: false);
        $this->assertContains('welcome.md', $resultCollision['skipped']);
        $this->assertEquals("# Modified Content", file_get_contents($vaultB.'/welcome.md'));

        // Test Collision Policy (overwrite when overwrite = true)
        $resultOverwrite = $extractor->extract($wsB, $jsonFile, overwrite: true);
        $this->assertContains('welcome.md', $resultOverwrite['extracted']);
        $this->assertStringContainsString('Welcome Note', file_get_contents($vaultB.'/welcome.md'));
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
