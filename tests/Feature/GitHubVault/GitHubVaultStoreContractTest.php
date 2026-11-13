<?php

namespace Tests\Feature\GitHubVault;

use App\Domain\Vault\Store\GitHub\GitHubGit;
use App\Domain\Vault\Store\GitHub\GitHubVaultStore;
use App\Domain\Vault\Store\VaultStore;
use App\Domain\Vault\Store\VaultStoreResolver;
use App\Models\Tenant;
use Tests\Support\InteractsWithFakeGitHub;
use Tests\Support\VaultStoreContractTest;

/** The shared VaultStore contract, run on the GitHub driver against the fake API (#440). */
class GitHubVaultStoreContractTest extends VaultStoreContractTest
{
    use InteractsWithFakeGitHub;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFakeGitHub();
        $this->github->createRepo('acme/notes');
    }

    protected function makeStore(): VaultStore
    {
        $tenant = Tenant::create(['slug' => 'acme-'.uniqid(), 'name' => 'Acme']);
        $workspace = $this->workspaceOn($this->patStore($tenant));

        return app(VaultStoreResolver::class)->for($workspace);
    }

    public function test_resolver_returns_the_github_driver_with_write_behind_capabilities(): void
    {
        $store = $this->makeStore();

        $this->assertInstanceOf(GitHubVaultStore::class, $store);
        $this->assertSame('github', $store->driver());
        $this->assertTrue($store->capabilities()->writeBehind);
        $this->assertFalse($store->capabilities()->emptyDirs);
        $this->assertSame(10 * 1024 * 1024, $store->capabilities()->maxFileBytes);
    }

    public function test_version_is_the_git_blob_sha_computed_locally_and_no_write_reaches_github(): void
    {
        $store = $this->makeStore();

        $entry = $store->write('a.md', "hello\n");

        $this->assertSame(GitHubGit::blobSha("hello\n"), $entry->version);
        $this->assertSame('ce013625030ba8dba906f756967f9e9ca394464a', $entry->version); // `git hash-object` of "hello\n"
        $this->assertSame(0, $this->github->writeCount());
    }
}
