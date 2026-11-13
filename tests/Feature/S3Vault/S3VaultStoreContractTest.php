<?php

namespace Tests\Feature\S3Vault;

use App\Domain\Vault\Store\S3\S3VaultStore;
use App\Domain\Vault\Store\StoreVersionConflict;
use App\Domain\Vault\Store\VaultStore;
use App\Domain\Vault\Store\VaultStoreResolver;
use App\Models\Tenant;
use Tests\Support\InteractsWithFakeS3;
use Tests\Support\VaultStoreContractTest;

/** The shared VaultStore contract, run on the S3 driver against the in-memory S3 API (#446). */
class S3VaultStoreContractTest extends VaultStoreContractTest
{
    use InteractsWithFakeS3;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFakeS3();
    }

    protected function makeStore(): VaultStore
    {
        $tenant = Tenant::create(['slug' => 'acme-'.uniqid(), 'name' => 'Acme']);

        return app(VaultStoreResolver::class)->for($this->workspaceOnS3($this->s3Store($tenant)));
    }

    public function test_resolver_returns_the_s3_driver_with_synchronous_capabilities(): void
    {
        $store = $this->makeStore();

        $this->assertInstanceOf(S3VaultStore::class, $store);
        $this->assertSame('s3', $store->driver());
        $this->assertFalse($store->capabilities()->writeBehind);
        $this->assertTrue($store->capabilities()->conditionalWrite);
        $this->assertTrue($store->capabilities()->presignedUrls);
    }

    public function test_objects_live_under_the_workspace_prefix_with_their_sha256(): void
    {
        $store = $this->makeStore();
        $entry = $store->write('notes/a.md', "# A\n");

        $key = $store->prefix().'/notes/a.md';
        $this->assertStringStartsWith('tenants/acme-', $key);
        $object = $this->s3->object(self::S3_BUCKET, $key);
        $this->assertNotNull($object);
        $this->assertSame(hash('sha256', "# A\n"), $object['meta']['sha256']);
        $this->assertSame(md5("# A\n"), $entry->version, 'The version is the ETag without quotes.');
        $this->assertSame(hash('sha256', "# A\n"), $store->storedSha256('notes/a.md'));
    }

    public function test_folders_are_marker_objects_that_list_never_returns(): void
    {
        $store = $this->makeStore();
        $store->makeDirectory('empty/inner');

        $this->assertContains($store->prefix().'/empty/inner/', $this->s3->keys(self::S3_BUCKET));
        $this->assertTrue($store->isDirectory('empty'));
        $this->assertSame([], $store->list()->entries);
    }

    public function test_a_stale_if_match_is_refused_by_the_provider_without_overwriting(): void
    {
        $store = $this->makeStore();
        $version = $store->write('c.md', 'base')->version;
        // Someone else writes directly to the bucket.
        $this->s3->putExternal(self::S3_BUCKET, $store->prefix().'/c.md', 'theirs');
        $this->newS3Request();
        $store = app(VaultStoreResolver::class)->for(\App\Models\Workspace::query()->latest('id')->first());

        try {
            $store->write('c.md', 'mine', $version);
            $this->fail('A stale If-Match must be refused.');
        } catch (StoreVersionConflict $conflict) {
            $this->assertSame(md5('theirs'), $conflict->currentVersion);
        }
        $this->assertSame('theirs', $this->s3->object(self::S3_BUCKET, $store->prefix().'/c.md')['body']);
    }

    public function test_without_native_conditional_writes_the_lock_and_head_still_refuse_a_stale_version(): void
    {
        $tenant = Tenant::create(['slug' => 'gcs', 'name' => 'GCS']);
        $store = app(VaultStoreResolver::class)->for($this->workspaceOnS3($this->s3Store($tenant, ['conditional_writes' => false])));
        $this->s3->noConditionalWrites();
        $this->assertFalse($store->capabilities()->conditionalWrite);

        $version = $store->write('g.md', 'base')->version;
        $store->write('g.md', 'next', $version);

        $this->expectException(StoreVersionConflict::class);
        $store->write('g.md', 'stale', $version);
    }

    public function test_gcs_endpoints_default_to_lock_and_head(): void
    {
        $tenant = Tenant::create(['slug' => 'gcs2', 'name' => 'GCS']);
        $record = $this->s3Store($tenant, ['endpoint' => 'https://storage.googleapis.com']);

        $this->assertFalse(app(\App\Domain\Vault\Store\S3\S3StoreFactory::class)->conditionalWrites($record));
    }

    public function test_reads_are_memoized_within_a_request(): void
    {
        $store = $this->makeStore();
        $store->write('m.md', 'memo');
        $this->newS3Request();
        $store = app(VaultStoreResolver::class)->for(\App\Models\Workspace::query()->latest('id')->first());

        $before = $this->s3->count('GET');
        $store->read('m.md');
        $store->read('m.md');
        $this->assertSame(1, $this->s3->count('GET') - $before);
    }
}
