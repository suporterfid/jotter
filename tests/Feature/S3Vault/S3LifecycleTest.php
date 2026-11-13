<?php

namespace Tests\Feature\S3Vault;

use App\Domain\Health\InstanceDoctor;
use App\Domain\Privacy\ErasureLog;
use App\Domain\Vault\Store\S3\S3Doctor;
use App\Domain\Vault\VaultStorage;
use App\Models\Note;
use App\Models\Tenant;
use App\Models\TenantVaultStore;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\Support\InteractsWithFakeS3;
use Tests\TestCase;
use ZipArchive;

/**
 * Lifecycle and LGPD with a workspace in an S3 bucket (#447, plan §6.2–§6.4).
 * DatabaseMigrations: restore truncates tables, which commits any open
 * transaction on MySQL.
 */
class S3LifecycleTest extends TestCase
{
    use DatabaseMigrations;
    use InteractsWithFakeS3;

    private Tenant $tenant;

    private TenantVaultStore $store;

    private Workspace $workspace;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFakeS3();
        $this->dir = storage_path('app/private/s3-lifecycle-'.uniqid());
        config([
            'jotter.backup.path' => $this->dir,
            'jotter.erasure_log.path' => $this->dir.'/erasure-log.ndjson',
        ]);
        File::ensureDirectoryExists($this->dir);

        Tenant::create(['slug' => 'other', 'name' => 'Other']);
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);
        $this->store = $this->s3Store($this->tenant);
        $this->workspace = $this->workspaceOnS3($this->store);
        $storage = app(VaultStorage::class);
        $storage->write($this->workspace, 'Home.md', "# Home\n");
        $storage->write($this->workspace, 'Ideas/One.md', "# One\n");
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        File::deleteDirectory((string) $this->workspace->vault_path);
        File::deleteDirectory($this->workspace->vault_path.'.remote-copy');
        parent::tearDown();
    }

    public function test_an_unversioned_bucket_is_included_in_the_backup(): void
    {
        $this->assertSame(0, Artisan::call('jotter:backup', ['--json' => true, '--unencrypted' => true]));
        $summary = json_decode(Artisan::output(), true);
        [$manifest, $names] = $this->archive($summary['path']);

        $this->assertContains('vaults/'.$this->workspace->id.'/Home.md', $names);
        $this->assertContains('vaults/'.$this->workspace->id.'/Ideas/One.md', $names);
        $entry = collect($manifest['workspaces'])->firstWhere('id', $this->workspace->id);
        $this->assertSame('s3', $entry['driver']);
        $this->assertTrue($entry['included']);
        $this->assertSame('bucket versioning off or unknown', $summary['remote_vaults'][0]['reason']);
    }

    public function test_a_versioned_bucket_is_left_out_and_the_operator_can_force_either_way(): void
    {
        $this->s3->versioning[self::S3_BUCKET] = 'Enabled';

        $this->assertSame(0, Artisan::call('jotter:backup', ['--json' => true, '--unencrypted' => true]));
        [, $names] = $this->archive(json_decode(Artisan::output(), true)['path']);
        $this->assertSame([], $this->workspaceEntries($names));

        $this->assertSame(0, Artisan::call('jotter:backup', ['--json' => true, '--unencrypted' => true, '--with-remote-vaults' => true]));
        [, $names] = $this->archive(json_decode(Artisan::output(), true)['path']);
        $this->assertCount(2, $this->workspaceEntries($names));

        $this->s3->versioning = [];
        $this->assertSame(0, Artisan::call('jotter:backup', ['--json' => true, '--unencrypted' => true, '--without-remote-vaults' => true]));
        [, $names] = $this->archive(json_decode(Artisan::output(), true)['path']);
        $this->assertSame([], $this->workspaceEntries($names), '--without-remote-vaults excludes even an unversioned bucket.');

        $this->artisan('jotter:backup', ['--with-remote-vaults' => true, '--without-remote-vaults' => true])->assertFailed();
    }

    public function test_an_unreachable_bucket_does_not_fail_the_backup(): void
    {
        $this->newS3Request();
        $this->s3->down();

        $this->assertSame(0, Artisan::call('jotter:backup', ['--json' => true, '--unencrypted' => true]));
        $summary = json_decode(Artisan::output(), true);

        $this->assertFalse($summary['remote_vaults'][0]['included']);
        $this->assertStringStartsWith('unreachable', $summary['remote_vaults'][0]['reason']);
    }

    public function test_restore_puts_back_what_the_bucket_lost_and_reindexes_from_it(): void
    {
        $this->assertSame(0, Artisan::call('jotter:backup', ['--json' => true, '--unencrypted' => true]));
        $archive = json_decode(Artisan::output(), true)['path'];

        // After the backup: one object deleted by mistake, another edited outside Jotter.
        $prefix = (string) $this->workspace->vault_prefix;
        unset($this->s3->buckets[self::S3_BUCKET][$prefix.'/Home.md']);
        $this->s3->putExternal(self::S3_BUCKET, $prefix.'/Ideas/One.md', "# One edited\n");
        $this->newS3Request();

        $this->assertSame(0, Artisan::call('jotter:restore', ['archive' => $archive, '--force' => true, '--json' => true]));
        $result = json_decode(Artisan::output(), true);

        $this->assertSame(['restored' => 1, 'reindexed' => 1, 'failed' => []], $result['s3']);
        $this->assertSame("# Home\n", $this->s3->object(self::S3_BUCKET, $prefix.'/Home.md')['body'], 'The lost object is back.');
        $this->assertSame("# One edited\n", $this->s3->object(self::S3_BUCKET, $prefix.'/Ideas/One.md')['body'], 'The bucket is the truth: a newer object is never overwritten.');
        $this->assertSame('One edited', Note::query()->where('path', 'Ideas/One.md')->value('title'));
        $this->assertDirectoryDoesNotExist($this->workspace->vault_path.'.remote-copy', 'Everything is in the bucket again.');
        $this->assertSame(TenantVaultStore::STATUS_UNTESTED, $this->store->fresh()->status);
    }

    public function test_restore_replays_the_tombstone_and_deletes_the_objects(): void
    {
        $this->assertSame(0, Artisan::call('jotter:backup', ['--json' => true, '--unencrypted' => true]));
        $archive = json_decode(Artisan::output(), true)['path'];

        (new ErasureLog)->record('tenant', (int) $this->tenant->id, $this->tenant->created_at?->toIso8601String(), 'cli', ['slug' => 'acme']);

        $this->assertSame(0, Artisan::call('jotter:restore', ['archive' => $archive, '--force' => true, '--json' => true]));
        $result = json_decode(Artisan::output(), true);

        $this->assertSame(1, $result['erasures']['tenants']);
        $this->assertNull(Tenant::query()->where('slug', 'acme')->first());
        $this->assertSame([], $this->s3->keys(self::S3_BUCKET, 'tenants/acme/'));
    }

    public function test_tenant_delete_empties_the_workspace_prefix_and_every_move_prefix(): void
    {
        // A retained source of an earlier move to another prefix of the same bucket.
        $old = $this->s3Store($this->tenant, ['prefix' => 'old']);
        $this->s3->putExternal(self::S3_BUCKET, 'old/docs-'.$this->workspace->id.'/Stale.md', 'stale');
        \App\Models\VaultMove::create([
            'workspace_id' => $this->workspace->id, 'from_store_id' => $old->id, 'from_prefix' => 'old/docs-'.$this->workspace->id,
            'to_store_id' => $this->store->id, 'to_prefix' => (string) $this->workspace->vault_prefix,
            'status' => 'completed', 'files_total' => 1, 'bytes_total' => 5, 'retain_until' => now()->addDays(7),
        ]);
        $this->s3->putExternal(self::S3_BUCKET, 'tenants/other/keep.md', 'not acme');

        $this->artisan('tenant:delete', ['slug' => 'acme', '--dry-run' => true])
            ->expectsOutputToContain('S3 location s3://'.self::S3_BUCKET.'/'.$this->workspace->vault_prefix)
            ->assertSuccessful();
        $this->assertCount(2, $this->s3->keys(self::S3_BUCKET, 'tenants/acme/'), 'A dry run changes nothing.');

        $this->artisan('tenant:delete', ['slug' => 'acme', '--force' => true])
            ->expectsOutputToContain('S3 s3://'.self::S3_BUCKET.'/'.$this->workspace->vault_prefix.': erased')
            ->assertSuccessful();

        $this->assertSame([], $this->s3->keys(self::S3_BUCKET, 'tenants/acme/'));
        $this->assertSame([], $this->s3->keys(self::S3_BUCKET, 'old/'));
        $this->assertSame(['tenants/other/keep.md'], $this->s3->keys(self::S3_BUCKET, 'tenants/other/'));
        $audit = DB::table('audit_log')->where('event', 'vault.remote_erased')->get();
        $this->assertCount(2, $audit, 'The erasure outlives the tenant rows.');
    }

    public function test_doctor_warns_about_versioning_and_old_version_expiry(): void
    {
        $check = (new S3Doctor)->check(true);
        $this->assertTrue($check->passed);
        $this->assertStringContainsString('bucket versioning is off', $check->message);

        $this->s3->versioning[self::S3_BUCKET] = 'Enabled';
        $this->assertStringContainsString('old versions never expire', (new S3Doctor)->check(true)->message);

        $this->s3->lifecycle[self::S3_BUCKET] = 90;
        $this->assertStringContainsString('expire after 90 days', (new S3Doctor)->check(true)->message);

        $this->s3->lifecycle[self::S3_BUCKET] = 30;
        $message = (new S3Doctor)->check(true)->message;
        $this->assertStringNotContainsString('versioning', $message);
        $this->assertStringContainsString('1 S3 store working', $message);
    }

    public function test_doctor_fails_for_a_refused_store_or_a_degraded_workspace(): void
    {
        $this->store->forceFill(['status' => TenantVaultStore::STATUS_FAILED, 'last_error' => 'HTTP 403 AccessDenied'])->save();
        $check = (new S3Doctor)->check(true);
        $this->assertFalse($check->passed);
        $this->assertStringContainsString('refused access', $check->message);

        $this->store->forceFill(['status' => TenantVaultStore::STATUS_OK])->save();
        $this->workspace->forceFill(['storage_state' => 'degraded'])->save();
        $this->assertStringContainsString('is degraded', (new S3Doctor)->check(true)->message);

        $ids = array_map(fn ($c) => $c->id, app(InstanceDoctor::class)->run()->checks);
        $this->assertContains('s3_vault', $ids);
    }

    /** @param list<string> $names @return list<string> */
    private function workspaceEntries(array $names): array
    {
        return array_values(array_filter($names, fn (string $name): bool => str_starts_with($name, 'vaults/'.$this->workspace->id.'/')));
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
