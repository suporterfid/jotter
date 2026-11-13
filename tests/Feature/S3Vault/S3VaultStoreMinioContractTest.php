<?php

namespace Tests\Feature\S3Vault;

use App\Domain\Vault\Store\S3\S3StoreFactory;
use App\Domain\Vault\Store\StoreVersionConflict;
use App\Domain\Vault\Store\VaultStore;
use App\Domain\Vault\Store\VaultStoreResolver;
use App\Models\Tenant;
use App\Models\TenantVaultStore;
use App\Models\Workspace;
use Tests\Support\VaultStoreContractTest;

/**
 * The shared VaultStore contract against a real S3-compatible server: the
 * MinIO service of `jt ci` (compose.ci.yaml), or any endpoint given in
 * JOTTER_TEST_S3_ENDPOINT / _KEY / _SECRET (optionally _BUCKET, _REGION,
 * _PATH_STYLE=0 for virtual-host style). Skipped when no endpoint is set.
 *
 * #448 runs this same class against R2, AWS S3 and GCS with real credentials,
 * kept outside the repository.
 */
class S3VaultStoreMinioContractTest extends VaultStoreContractTest
{
    protected function setUp(): void
    {
        parent::setUp();

        if ((string) getenv('JOTTER_TEST_S3_ENDPOINT') === '') {
            $this->markTestSkipped('No S3-compatible endpoint (JOTTER_TEST_S3_ENDPOINT); jt ci starts MinIO for this.');
        }

        $host = strtolower((string) parse_url((string) getenv('JOTTER_TEST_S3_ENDPOINT'), PHP_URL_HOST));
        config([
            'jotter.vault_stores.drivers' => ['local', 's3'],
            // A CI MinIO lives on a private address: the operator allowlist lets it through (§5).
            'jotter.vault_stores.allowed_private_hosts' => [$host],
        ]);
        $this->app->forgetScopedInstances();
        $this->ensureBucket();
    }

    protected function makeStore(): VaultStore
    {
        $tenant = Tenant::create(['slug' => 'contract-'.bin2hex(random_bytes(4)), 'name' => 'Contract']);
        $record = $this->record($tenant);
        $workspace = Workspace::create([
            'tenant_id' => $tenant->id,
            'slug' => 'docs',
            'name' => 'Docs',
            'vault_path' => storage_path('app/vaults/s3-contract-'.uniqid()),
        ]);
        $workspace->forceFill(['vault_store_id' => $record->id, 'vault_prefix' => 'jotter-contract/'.$tenant->slug])->save();

        $store = app(VaultStoreResolver::class)->for($workspace->fresh());
        $this->beforeApplicationDestroyed(static function () use ($store): void {
            try {
                $store->deleteDirectory('');
            } catch (\Throwable) {
                // best effort: the bucket is disposable
            }
        });

        return $store;
    }

    public function test_a_concurrent_external_write_makes_the_conditional_write_fail(): void
    {
        $store = $this->makeStore();
        $version = $store->write('race.md', 'base')->version;

        $client = app(S3StoreFactory::class)->client(TenantVaultStore::query()->latest('id')->firstOrFail());
        $client->putObject(['Bucket' => $this->bucket(), 'Key' => 'jotter-contract/'.Tenant::query()->latest('id')->value('slug').'/race.md', 'Body' => 'external'])->resolve();
        app(S3StoreFactory::class)->forgetStores();
        $store = app(VaultStoreResolver::class)->for(Workspace::query()->latest('id')->firstOrFail());

        $this->expectException(StoreVersionConflict::class);
        $store->write('race.md', 'mine', $version);
    }

    private function record(Tenant $tenant): TenantVaultStore
    {
        return TenantVaultStore::create([
            'tenant_id' => $tenant->id,
            'name' => 'Contract bucket',
            'driver' => 's3',
            'settings' => [
                'endpoint' => (string) getenv('JOTTER_TEST_S3_ENDPOINT'),
                'region' => (string) (getenv('JOTTER_TEST_S3_REGION') ?: 'us-east-1'),
                'bucket' => $this->bucket(),
                'prefix' => 'jotter-contract',
                'path_style' => getenv('JOTTER_TEST_S3_PATH_STYLE') !== '0',
            ],
            'credentials' => [
                'access_key_id' => (string) getenv('JOTTER_TEST_S3_KEY'),
                'secret_access_key' => (string) getenv('JOTTER_TEST_S3_SECRET'),
            ],
            'status' => 'ok',
        ]);
    }

    private function bucket(): string
    {
        return (string) (getenv('JOTTER_TEST_S3_BUCKET') ?: 'jotter-contract');
    }

    private function ensureBucket(): void
    {
        $tenant = Tenant::create(['slug' => 'contract-setup-'.bin2hex(random_bytes(3)), 'name' => 'Setup']);
        $client = app(S3StoreFactory::class)->client($this->record($tenant));
        try {
            $client->createBucket(['Bucket' => $this->bucket()])->resolve();
        } catch (\AsyncAws\Core\Exception\Http\ClientException) {
            // already exists (409 BucketAlreadyOwnedByYou) or not ours to create: use it as is
        }
    }
}
