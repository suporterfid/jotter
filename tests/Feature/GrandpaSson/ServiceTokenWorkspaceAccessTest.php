<?php

namespace Tests\Feature\GrandpaSson;

use App\Domain\Auth\Contracts\IdentityProvider;
use App\Domain\Auth\GrandpaSson\IntrospectionResult;
use App\Domain\Auth\Providers\GrandpaSSOnIdentityProvider;
use App\Models\Tenant;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeIntrospectionClient;
use Tests\TestCase;

final class ServiceTokenWorkspaceAccessTest extends TestCase
{
    use RefreshDatabase;

    private function makeWorkspace(): Workspace
    {
        $tenant = Tenant::create(['slug' => 'svc-test-'.uniqid(), 'name' => 'Service Token Test']);
        $vaultPath = storage_path('app/vaults/svc_workspace_'.uniqid());
        mkdir($vaultPath, 0755, true);

        return Workspace::create([
            'tenant_id' => $tenant->id,
            'slug' => 'svc-'.uniqid(),
            'name' => 'Service Token Workspace',
            'vault_path' => $vaultPath,
        ]);
    }

    private function bindProvider(IntrospectionResult $result): void
    {
        config([
            'jotter.auth_provider' => 'grandpasson',
            'jotter.grandpasson_resource.inbound_enabled' => true,
        ]);

        $this->app->singleton(IdentityProvider::class, fn () => new GrandpaSSOnIdentityProvider(
            new FakeIntrospectionClient($result)
        ));
    }

    public function test_read_scope_allows_a_get_request(): void
    {
        $workspace = $this->makeWorkspace();
        $this->bindProvider(new IntrospectionResult(
            active: true,
            scopes: ['kb:read'],
            audiences: ["workspace/{$workspace->id}"],
            clientId: 'svc-acme',
        ));

        $response = $this->withHeader('Authorization', 'Bearer token')
            ->getJson("/api/workspaces/{$workspace->id}/notes");

        $response->assertOk();
    }

    public function test_read_only_scope_rejects_a_write_request(): void
    {
        $workspace = $this->makeWorkspace();
        $this->bindProvider(new IntrospectionResult(
            active: true,
            scopes: ['kb:read'],
            audiences: ["workspace/{$workspace->id}"],
            clientId: 'svc-acme',
        ));

        $response = $this->withHeader('Authorization', 'Bearer token')
            ->postJson("/api/workspaces/{$workspace->id}/notes", [
                'path' => 'test.md',
                'content' => '# Test',
            ]);

        $response->assertStatus(403)
            ->assertJsonPath('message', 'Token does not have the required scope.');
    }

    public function test_write_scope_allows_a_write_request(): void
    {
        $workspace = $this->makeWorkspace();
        $this->bindProvider(new IntrospectionResult(
            active: true,
            scopes: ['kb:read', 'kb:write'],
            audiences: ["workspace/{$workspace->id}"],
            clientId: 'svc-acme',
        ));

        $response = $this->withHeader('Authorization', 'Bearer token')
            ->postJson("/api/workspaces/{$workspace->id}/notes", [
                'path' => 'test.md',
                'content' => '# Test',
            ]);

        $response->assertCreated();
    }

    public function test_a_token_scoped_to_a_different_workspace_is_forbidden(): void
    {
        $workspace = $this->makeWorkspace();
        $otherWorkspace = $this->makeWorkspace();
        $this->bindProvider(new IntrospectionResult(
            active: true,
            scopes: ['kb:read', 'kb:write'],
            audiences: ["workspace/{$otherWorkspace->id}"],
            clientId: 'svc-acme',
        ));

        $response = $this->withHeader('Authorization', 'Bearer token')
            ->getJson("/api/workspaces/{$workspace->id}/notes");

        $response->assertStatus(403)
            ->assertJsonPath('message', 'Forbidden workspace access.');
    }
}
