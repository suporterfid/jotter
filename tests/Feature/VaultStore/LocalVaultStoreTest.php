<?php

namespace Tests\Feature\VaultStore;

use App\Domain\Vault\Exceptions\PathTraversalRejected;
use App\Domain\Vault\Store\LocalVaultStore;
use App\Domain\Vault\Store\VaultStore;
use App\Domain\Vault\Store\VaultStoreResolver;
use App\Models\Tenant;
use App\Models\Workspace;
use Illuminate\Support\Facades\File;
use Tests\Support\VaultStoreContractTest;

class LocalVaultStoreTest extends VaultStoreContractTest
{
    private string $root;

    private ?Workspace $workspace = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/jotter-store-'.uniqid('', true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        File::deleteDirectory($this->root.'-outside');

        parent::tearDown();
    }

    protected function makeStore(): VaultStore
    {
        return new LocalVaultStore($this->root.'/vault', $this->workspace());
    }

    public function test_resolver_returns_the_local_folder_for_a_workspace_without_a_store(): void
    {
        $store = app(VaultStoreResolver::class)->for($this->workspace());

        $this->assertInstanceOf(LocalVaultStore::class, $store);
        $this->assertSame('local', $store->driver());
        $store->write('hello.md', 'hi');
        $this->assertFileExists($this->root.'/vault/hello.md');
    }

    public function test_local_version_is_the_sha256_of_the_bytes(): void
    {
        $entry = $this->makeStore()->write('h.md', 'content');

        $this->assertSame(hash('sha256', 'content'), $entry->version);
    }

    public function test_symlink_escapes_are_rejected_and_skipped_in_listings(): void
    {
        $store = $this->makeStore();
        $store->write('inside.md', 'in');
        mkdir($this->root.'-outside', 0755, true);
        file_put_contents($this->root.'-outside/secret.md', 'secret');
        symlink($this->root.'-outside', $this->root.'/vault/link');

        $this->assertSame(['inside.md'], array_map(fn ($e) => $e->path, $store->list()->entries));

        $this->expectException(PathTraversalRejected::class);
        $store->read('link/secret.md');
    }

    public function test_listing_a_missing_root_does_not_create_it(): void
    {
        $this->assertSame([], $this->makeStore()->list()->entries);
        $this->assertDirectoryDoesNotExist($this->root.'/vault');
    }

    private function workspace(): Workspace
    {
        if ($this->workspace === null) {
            $tenant = Tenant::create(['slug' => 'store-'.uniqid(), 'name' => 'Store']);
            $this->workspace = Workspace::create([
                'tenant_id' => $tenant->id,
                'slug' => 'ws',
                'name' => 'WS',
                'vault_path' => $this->root.'/vault',
            ]);
        }

        return $this->workspace;
    }
}
