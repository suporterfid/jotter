<?php

namespace Tests\Feature;

use App\Domain\Vault\VaultStorage;
use App\Models\AuditLog;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use ZipArchive;

final class TenantExportCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $scratch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scratch = storage_path('framework/testing/export-'.uniqid());
        File::ensureDirectoryExists($this->scratch.'/vault/attachments');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->scratch);

        parent::tearDown();
    }

    public function test_export_produces_a_zip_with_vault_files_json_backup_and_manifest(): void
    {
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'plan_status' => 'active', 'plan_seats' => 3]);
        $workspace = Workspace::create(['tenant_id' => $tenant->id, 'slug' => 'docs', 'name' => 'Docs', 'vault_path' => $this->scratch.'/vault']);
        $owner = User::factory()->create(['email' => 'owner@acme.example']);
        Membership::create(['tenant_id' => $tenant->id, 'workspace_id' => $workspace->id, 'subject_id' => (string) $owner->id, 'role' => 'owner']);
        app(VaultStorage::class)->write($workspace, 'readme.md', "# Readme\n");
        app(VaultStorage::class)->write($workspace, 'guides/setup.md', "# Setup\n");
        file_put_contents($this->scratch.'/vault/attachments/logo.png', 'png-bytes');

        $to = $this->scratch.'/out/acme.zip';
        $this->assertSame(0, Artisan::call('tenant:export', ['slug' => 'acme', '--to' => $to, '--json' => true]));
        $summary = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('acme', $summary['tenant']);
        $this->assertSame(1, $summary['workspaces']);
        $this->assertFileExists($to);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($to));
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $this->assertContains('workspaces/docs/vault/readme.md', $names);
        $this->assertContains('workspaces/docs/vault/guides/setup.md', $names);
        $this->assertContains('workspaces/docs/vault/attachments/logo.png', $names);
        $this->assertContains('workspaces/docs/backup.json', $names);
        $this->assertContains('tenant.json', $names);

        $backup = json_decode((string) $zip->getFromName('workspaces/docs/backup.json'), true);
        $this->assertSame('1.0', $backup['version']);
        $this->assertSame('docs', $backup['workspace_slug']);
        $this->assertSame(['guides/setup.md', 'readme.md'], array_column($backup['notes'], 'path'));

        $manifest = json_decode((string) $zip->getFromName('tenant.json'), true);
        $this->assertSame('acme', $manifest['tenant']['slug']);
        $this->assertSame('active', $manifest['tenant']['plan']['status']);
        $this->assertSame('owner@acme.example', $manifest['users'][0]['email']);
        $this->assertSame('owner', $manifest['memberships'][0]['role']);
        $zip->close();

        $this->assertSame(1, AuditLog::query()->where('event', 'tenant.exported')->where('tenant_id', $tenant->id)->count());
    }

    public function test_export_refuses_unknown_tenants_and_the_document_root(): void
    {
        $this->artisan('tenant:export', ['slug' => 'missing'])->assertFailed();

        Tenant::create(['slug' => 'acme', 'name' => 'Acme']);
        $this->artisan('tenant:export', ['slug' => 'acme', '--to' => public_path('acme.zip')])
            ->expectsOutputToContain('Refusing to write the export under the document root.')
            ->assertFailed();
        $this->assertFileDoesNotExist(public_path('acme.zip'));
    }
}
