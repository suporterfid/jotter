<?php

namespace Tests\Support;

use App\Domain\Vault\Store\EndpointGuard;
use App\Models\Tenant;
use App\Models\TenantVaultStore;
use App\Models\Workspace;

/**
 * Wires FakeS3 and creates `s3` stores and workspaces on them for tests.
 */
trait InteractsWithFakeS3
{
    protected FakeS3 $s3;

    protected const S3_BUCKET = 'jotter-vaults';

    protected const S3_SECRET = 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY';

    protected function setUpFakeS3(bool $versioned = false): FakeS3
    {
        config(['jotter.vault_stores.drivers' => ['local', 's3']]);
        // Tests never touch real DNS.
        $this->app->instance(EndpointGuard::class, new EndpointGuard(
            static fn (string $host): array => $host === 's3.fake.test' ? ['93.184.216.34'] : [],
            [],
        ));
        $this->app->forgetScopedInstances();
        $this->s3 = FakeS3::install();
        $this->s3->createBucket(self::S3_BUCKET, $versioned);

        return $this->s3;
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    protected function s3Store(Tenant $tenant, array $settings = []): TenantVaultStore
    {
        return TenantVaultStore::create([
            'tenant_id' => $tenant->id,
            'name' => 'Bucket '.uniqid(),
            'driver' => 's3',
            'settings' => $settings + [
                'endpoint' => 'https://s3.fake.test',
                'region' => 'us-east-1',
                'bucket' => self::S3_BUCKET,
                'prefix' => 'tenants/'.$tenant->slug,
                'path_style' => true,
            ],
            'credentials' => ['access_key_id' => 'AKIAEXAMPLE', 'secret_access_key' => self::S3_SECRET],
            'status' => 'ok',
        ]);
    }

    protected function workspaceOnS3(TenantVaultStore $store, string $slug = 'docs'): Workspace
    {
        $workspace = Workspace::create([
            'tenant_id' => $store->tenant_id,
            'slug' => $slug,
            'name' => ucfirst($slug),
            'vault_path' => storage_path('app/vaults/s3-tests-'.uniqid()),
        ]);
        $prefix = trim((string) ($store->settings['prefix'] ?? ''), '/').'/'.$slug.'-'.$workspace->id;
        $workspace->forceFill(['vault_store_id' => $store->id, 'vault_prefix' => ltrim($prefix, '/')])->save();

        return $workspace->fresh();
    }

    /** Drop the per-request clients and memo, as a new request would. */
    protected function newS3Request(): void
    {
        app(\App\Domain\Vault\Store\S3\S3StoreFactory::class)->forgetStores();
    }
}
