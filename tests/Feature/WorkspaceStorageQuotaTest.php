<?php

namespace Tests\Feature;

use App\Domain\Quota\WorkspaceQuota;
use App\Domain\Vault\VaultStorage;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use ZipArchive;

/**
 * #390 item 2: per-workspace storage quota. Growth past the quota is refused
 * with 507; edits that do not grow the vault and deletes keep working.
 */
final class WorkspaceStorageQuotaTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Workspace $workspace;
    private string $vault;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['is_admin' => true]);
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);
        $this->vault = storage_path('app/vaults/quota_'.uniqid());
        File::ensureDirectoryExists($this->vault);
        File::put($this->vault.'/existing.md', str_repeat('a', 600));
        // 1 MB quota on this workspace; 600 bytes already used.
        $this->workspace = Workspace::create(['tenant_id' => $tenant->id, 'slug' => 'small', 'name' => 'Small', 'vault_path' => $this->vault, 'storage_quota_mb' => 1]);
        $this->actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->vault);
        parent::tearDown();
    }

    public function test_usage_is_measured_once_then_tracked_by_writes(): void
    {
        $quota = app(WorkspaceQuota::class);
        $this->assertSame(600, $quota->usage($this->workspace));
        $this->assertNotNull($this->workspace->fresh()->storage_measured_at);

        app(VaultStorage::class)->write($this->workspace, 'new.md', str_repeat('b', 400));
        $this->assertSame(1000, $this->workspace->fresh()->storage_bytes);

        app(VaultStorage::class)->write($this->workspace, 'new.md', str_repeat('b', 100));
        $this->assertSame(700, $this->workspace->fresh()->storage_bytes, 'shrinking a note gives the bytes back');
    }

    public function test_a_note_that_does_not_fit_is_refused_with_507_and_nothing_is_written(): void
    {
        $response = $this->postJson("/api/workspaces/{$this->workspace->id}/notes", [
            'path' => 'big.md',
            'content' => str_repeat('x', 1024 * 1024),
        ]);

        $response->assertStatus(507)->assertJsonPath('limit_bytes', 1024 * 1024)->assertJsonPath('used_bytes', 600);
        $this->assertFileDoesNotExist($this->vault.'/big.md');
    }

    public function test_a_full_workspace_can_still_edit_without_growing_and_delete(): void
    {
        $this->workspace->forceFill(['storage_quota_mb' => 1])->save();
        File::put($this->vault.'/huge.md', str_repeat('h', 1024 * 1024));
        app(WorkspaceQuota::class)->measure($this->workspace);
        $this->assertTrue(app(WorkspaceQuota::class)->status($this->workspace)['exceeded']);

        // Same size: allowed.
        app(VaultStorage::class)->write($this->workspace->fresh(), 'existing.md', str_repeat('z', 600));
        $this->assertSame(str_repeat('z', 600), file_get_contents($this->vault.'/existing.md'));

        // Growth: refused.
        $this->expectException(\App\Domain\Quota\QuotaExceeded::class);
        app(VaultStorage::class)->write($this->workspace->fresh(), 'existing.md', str_repeat('z', 601));
    }

    public function test_attachments_and_imports_respect_the_quota(): void
    {
        $this->postJson("/api/workspaces/{$this->workspace->id}/attachments", [
            'file' => UploadedFile::fake()->createWithContent('photo.png', str_repeat('p', 2 * 1024 * 1024)),
        ])->assertStatus(507);
        $this->assertFileDoesNotExist($this->vault.'/_resources/photo.png');

        $zipPath = storage_path('app/imports/quota_'.uniqid().'.zip');
        File::ensureDirectoryExists(dirname($zipPath));
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE);
        $zip->addFromString('a.md', str_repeat('a', 700 * 1024));
        $zip->addFromString('b.md', str_repeat('b', 700 * 1024));
        $zip->close();

        $this->post("/api/workspaces/{$this->workspace->id}/import", [
            'archive' => new UploadedFile($zipPath, 'vault.zip', 'application/zip', null, true),
        ], ['Accept' => 'application/json'])->assertStatus(507);
        $this->assertFileDoesNotExist($this->vault.'/a.md', 'nothing of the archive is written');
    }

    public function test_unlimited_and_default_quotas(): void
    {
        $quota = app(WorkspaceQuota::class);

        $this->workspace->forceFill(['storage_quota_mb' => 0])->save();
        $this->assertNull($quota->limitBytes($this->workspace));
        app(VaultStorage::class)->write($this->workspace, 'big.md', str_repeat('x', 2 * 1024 * 1024));

        $this->workspace->forceFill(['storage_quota_mb' => null])->save();
        config(['jotter.quota.default_mb' => 5]);
        $this->assertSame(5 * 1024 * 1024, $quota->limitBytes($this->workspace));
    }

    public function test_api_exposes_status_admin_sets_quota_and_command_reports_it(): void
    {
        File::put($this->vault.'/fill.md', str_repeat('f', 900 * 1024));
        app(WorkspaceQuota::class)->measure($this->workspace);

        $storage = collect($this->getJson('/api/workspaces')->assertOk()->json('data'))->firstWhere('slug', 'small')['storage'];
        $this->assertTrue($storage['warning']);
        $this->assertFalse($storage['exceeded']);
        $this->assertSame(1, $storage['quota_mb']);

        $this->putJson("/api/admin/workspaces/{$this->workspace->id}", ['storage_quota_mb' => 10])
            ->assertOk()->assertJsonPath('data.storage.quota_mb', 10)->assertJsonPath('data.storage.warning', false);

        $this->assertSame(0, Artisan::call('workspace:storage', ['workspace' => 'small', '--quota' => 'default', '--json' => true]));
        $row = json_decode(Artisan::output(), true)[0];
        $this->assertNull($row['quota_mb']);
        $this->assertSame((int) config('jotter.quota.default_mb') * 1024 * 1024, $row['limit_bytes']);
    }

    public function test_doctor_lists_workspaces_near_or_over_the_quota(): void
    {
        File::put($this->vault.'/fill.md', str_repeat('f', 900 * 1024));
        app(WorkspaceQuota::class)->measure($this->workspace);

        $checks = array_column(app(\App\Domain\Health\InstanceDoctor::class)->run()->toArray()['checks'], null, 'id');
        $this->assertSame('warn', $checks['storage_quota']['status']);
        $this->assertStringContainsString('small', $checks['storage_quota']['message']);
    }
}
