<?php

namespace Tests\Support;

use App\Models\Tenant;
use App\Models\TenantVaultStore;
use App\Models\Workspace;

/**
 * Wires FakeGitHub and creates `github` stores (PAT or App mode) for tests.
 */
trait InteractsWithFakeGitHub
{
    protected FakeGitHub $github;

    protected ?string $appKeyPath = null;

    protected function setUpFakeGitHub(bool $app = false): FakeGitHub
    {
        config([
            'jotter.vault_stores.drivers' => ['local', 'github'],
            'jotter.github.api_url' => 'https://api.github.test',
            'jotter.github.web_url' => 'https://github.test',
            'jotter.github.webhook_secret' => 'webhook-secret',
            'cache.default' => 'array',
        ]);
        $this->github = FakeGitHub::install();

        if ($app) {
            $pem = $this->github->createApp('4242');
            $this->appKeyPath = sys_get_temp_dir().'/jotter-app-key-'.uniqid().'.pem';
            file_put_contents($this->appKeyPath, $pem);
            config([
                'jotter.github.app_id' => '4242',
                'jotter.github.app_slug' => 'jotter-vault-test',
                'jotter.github.private_key_path' => $this->appKeyPath,
            ]);
        }

        return $this->github;
    }

    protected function tearDownFakeGitHub(): void
    {
        if ($this->appKeyPath !== null && is_file($this->appKeyPath)) {
            unlink($this->appKeyPath);
        }
    }

    protected function patStore(Tenant $tenant, string $fullName = 'acme/notes', string $branch = 'main', string $token = 'github_pat_test'): TenantVaultStore
    {
        $this->github->allowPat($token, $fullName);
        [$owner, $repo] = explode('/', $fullName);

        return TenantVaultStore::create([
            'tenant_id' => $tenant->id,
            'name' => 'GitHub '.$fullName.' '.uniqid(),
            'driver' => 'github',
            'settings' => ['owner' => $owner, 'repo' => $repo, 'branch' => $branch],
            'credentials' => ['token' => $token, 'webhook_secret' => 'repo-secret'],
            'status' => 'ok',
        ]);
    }

    protected function appStore(Tenant $tenant, int $installationId, string $fullName = 'acme/notes', string $branch = 'main'): TenantVaultStore
    {
        [$owner, $repo] = explode('/', $fullName);

        return TenantVaultStore::create([
            'tenant_id' => $tenant->id,
            'name' => 'GitHub App '.$fullName.' '.uniqid(),
            'driver' => 'github',
            'settings' => ['owner' => $owner, 'repo' => $repo, 'branch' => $branch, 'installation_id' => $installationId],
            'status' => 'ok',
        ]);
    }

    protected function workspaceOn(TenantVaultStore $store, string $slug = 'docs'): Workspace
    {
        $workspace = Workspace::create([
            'tenant_id' => $store->tenant_id,
            'slug' => $slug,
            'name' => ucfirst($slug),
            'vault_path' => storage_path('app/vaults/github-tests-'.uniqid()),
        ]);
        $workspace->forceFill(['vault_store_id' => $store->id, 'vault_prefix' => ''])->save();

        return $workspace->fresh();
    }
}
