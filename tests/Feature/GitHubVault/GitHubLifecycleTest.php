<?php

namespace Tests\Feature\GitHubVault;

use App\Domain\Privacy\ErasureLog;
use App\Domain\Vault\Store\GitHub\GitHubFlusher;
use App\Domain\Vault\Store\GitHub\GitHubLifecycle;
use App\Domain\Vault\Store\GitHub\GitHubStoreFactory;
use App\Domain\Vault\Store\VaultStoreResolver;
use App\Models\Note;
use App\Models\Tenant;
use App\Models\TenantVaultStore;
use App\Models\VaultRemoteFile;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\Support\InteractsWithFakeGitHub;
use Tests\TestCase;
use ZipArchive;

/**
 * Lifecycle and LGPD with a workspace on GitHub (#444). DatabaseMigrations:
 * restore truncates tables, which commits any open transaction on MySQL.
 */
class GitHubLifecycleTest extends TestCase
{
    use DatabaseMigrations;
    use InteractsWithFakeGitHub;

    private Tenant $tenant;

    private TenantVaultStore $store;

    private Workspace $workspace;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFakeGitHub();
        $this->github->createRepo('acme/vault');
        $this->dir = storage_path('app/private/github-lifecycle-'.uniqid());
        config([
            'jotter.backup.path' => $this->dir,
            'jotter.erasure_log.path' => $this->dir.'/erasure-log.ndjson',
        ]);
        File::ensureDirectoryExists($this->dir);

        Tenant::create(['slug' => 'other', 'name' => 'Other']);
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);
        $this->store = $this->patStore($this->tenant, 'acme/vault');
        $this->workspace = $this->workspaceOn($this->store);
        $store = app(VaultStoreResolver::class)->for($this->workspace);
        $store->write('Home.md', "# Home\n");
        $store->write('Ideas/One.md', "# One\n");
        app(GitHubFlusher::class)->flush($this->store);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        File::deleteDirectory((string) $this->workspace->vault_path);
        File::deleteDirectory($this->workspace->vault_path.'.remote-copy');
        parent::tearDown();
    }

    public function test_tenant_delete_empties_the_repository_and_prints_the_guidance(): void
    {
        $this->assertCount(2, $this->github->files('acme/vault'));
        $commits = count($this->github->log('acme/vault'));

        $this->artisan('tenant:delete', ['slug' => 'acme', '--dry-run' => true])
            ->expectsOutputToContain('GitHub repository acme/vault@main')
            ->assertSuccessful();
        $this->assertCount(2, $this->github->files('acme/vault'), 'A dry run changes nothing.');

        $this->artisan('tenant:delete', ['slug' => 'acme', '--force' => true])
            ->expectsOutputToContain('GitHub acme/vault@main: erased')
            ->expectsOutputToContain('Archive or delete the GitHub repository acme/vault@main')
            ->assertSuccessful();

        $this->assertSame([], $this->github->files('acme/vault'));
        $this->assertCount($commits + 1, $this->github->log('acme/vault'), 'One final removal commit; history is never rewritten.');
        $this->assertNull(Tenant::query()->where('slug', 'acme')->first());

        $audit = DB::table('audit_log')->where('event', 'vault.remote_erased')->first();
        $this->assertNotNull($audit, 'The guidance outlives the tenant rows.');
        $metadata = json_decode((string) $audit->metadata, true);
        $this->assertSame('erased', $metadata['status']);
        $this->assertSame('acme', $metadata['tenant']);
        $this->assertStringContainsString('Archive or delete', $metadata['guidance']);
    }

    public function test_an_unreachable_repository_does_not_block_the_deletion(): void
    {
        app(GitHubStoreFactory::class)->markFailed($this->store, 'token revoked');

        $this->artisan('tenant:delete', ['slug' => 'acme', '--force' => true])
            ->expectsOutputToContain('GitHub acme/vault@main: unreachable')
            ->assertSuccessful();

        $this->assertNull(Tenant::query()->where('slug', 'acme')->first());
        $this->assertCount(2, $this->github->files('acme/vault'), 'Nothing could be removed; the guidance says what to do.');
    }

    public function test_restore_replays_the_tombstone_with_a_removal_commit(): void
    {
        $this->assertSame(0, Artisan::call('jotter:backup', ['--json' => true, '--unencrypted' => true]));
        $archive = json_decode(Artisan::output(), true)['path'];

        // The tenant was deleted on another server (or the log came from a newer backup).
        (new ErasureLog)->record('tenant', (int) $this->tenant->id, $this->tenant->created_at?->toIso8601String(), 'cli', ['slug' => 'acme']);
        $commits = count($this->github->log('acme/vault'));

        $this->assertSame(0, Artisan::call('jotter:restore', ['archive' => $archive, '--force' => true, '--json' => true]));
        $result = json_decode(Artisan::output(), true);

        $this->assertSame(1, $result['erasures']['tenants']);
        $this->assertNull(Tenant::query()->where('slug', 'acme')->first());
        $this->assertSame([], $this->github->files('acme/vault'));
        $this->assertCount($commits + 1, $this->github->log('acme/vault'));
    }

    public function test_the_default_backup_leaves_the_github_workspace_out(): void
    {
        $this->assertSame(0, Artisan::call('jotter:backup', ['--json' => true, '--unencrypted' => true]));
        [$manifest, $names] = $this->archive(json_decode(Artisan::output(), true)['path']);

        $this->assertFalse($manifest['includes']['remote_vaults']);
        $this->assertTrue(collect($manifest['workspaces'])->firstWhere('id', $this->workspace->id)['remote']);
        $this->assertSame([], array_values(array_filter($names, fn (string $name): bool => str_starts_with($name, 'vaults/'.$this->workspace->id.'/'))));
        $this->assertArrayNotHasKey('vault_remote_files', $manifest['tables'], 'The manifest is rebuilt from the repository.');
        $this->assertArrayNotHasKey('vault_blob_cache', $manifest['tables']);
    }

    public function test_with_remote_vaults_the_backup_copies_the_repository_files(): void
    {
        $this->assertSame(0, Artisan::call('jotter:backup', ['--json' => true, '--unencrypted' => true, '--with-remote-vaults' => true]));
        $path = json_decode(Artisan::output(), true)['path'];
        [$manifest, $names] = $this->archive($path);

        $this->assertTrue($manifest['includes']['remote_vaults']);
        $this->assertContains('vaults/'.$this->workspace->id.'/Home.md', $names);
        $this->assertContains('vaults/'.$this->workspace->id.'/Ideas/One.md', $names);

        // Restored beside the folder, never over the repository.
        $this->assertSame(0, Artisan::call('jotter:restore', ['archive' => $path, '--force' => true, '--json' => true]));
        $this->assertSame("# Home\n", file_get_contents($this->workspace->vault_path.'.remote-copy/Home.md'));
        $this->assertCount(2, $this->github->files('acme/vault'));
    }

    public function test_after_a_restore_the_repository_is_read_again_and_reprojected(): void
    {
        $this->assertSame(0, Artisan::call('jotter:backup', ['--json' => true, '--unencrypted' => true]));
        $archive = json_decode(Artisan::output(), true)['path'];

        // Life goes on after the backup: someone pushes from a laptop.
        $this->github->push('acme/vault', ['From laptop.md' => "# From laptop\n"]);

        $this->assertSame(0, Artisan::call('jotter:restore', ['archive' => $archive, '--force' => true, '--json' => true]));
        $this->assertSame(0, VaultRemoteFile::query()->count(), 'The old manifest is dropped.');
        $this->assertSame(TenantVaultStore::STATUS_UNTESTED, $this->store->fresh()->status);

        $this->artisan('vault:pull')->assertSuccessful();

        $this->assertSame(3, VaultRemoteFile::query()->where('vault_store_id', $this->store->id)->count());
        $this->assertNotNull(Note::query()->where('workspace_id', $this->workspace->id)->where('path', 'From laptop.md')->first());
        $this->assertSame("# From laptop\n", app(VaultStoreResolver::class)->for($this->workspace->fresh())->read('From laptop.md'));
    }

    public function test_the_workspace_sync_badge(): void
    {
        $this->assertSame(['driver' => 'github', 'state' => 'synced', 'pending' => 0], GitHubLifecycle::status($this->workspace));

        app(VaultStoreResolver::class)->for($this->workspace)->write('New.md', "# New\n");
        $this->assertSame('pending', GitHubLifecycle::status($this->workspace)['state']);

        $this->travel(10)->minutes();
        $this->assertSame('delayed', GitHubLifecycle::status($this->workspace)['state']);

        app(GitHubStoreFactory::class)->markFailed($this->store, 'token revoked');
        $this->assertSame('read_only', GitHubLifecycle::status($this->workspace->fresh())['state']);

        $local = Workspace::create(['tenant_id' => $this->tenant->id, 'slug' => 'local', 'name' => 'Local', 'vault_path' => storage_path('app/vaults/github-lifecycle-local-'.uniqid())]);
        $this->assertNull(GitHubLifecycle::status($local));
    }

    /** @return array{0: array<string, mixed>, 1: list<string>} */
    private function archive(string $path): array
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = (string) $zip->getNameIndex($i);
        }
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        $zip->close();

        return [$manifest, $names];
    }
}
