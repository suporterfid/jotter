<?php

namespace Tests\Feature;

use App\Domain\Auth\Contracts\IdentityProvider;
use App\Domain\Auth\CredentialScopedIdentityProvider;
use App\Domain\Auth\GrandpaSson\IntrospectionResult;
use App\Domain\Auth\MachineTokenIssuer;
use App\Domain\Auth\MachineTokenOptions;
use App\Domain\Auth\Providers\GrandpaSSOnIdentityProvider;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeIntrospectionClient;
use Tests\TestCase;

/**
 * Machine tokens authenticate MCP whatever the configured identity provider is
 * (before this change only the local provider resolved them).
 */
final class TokenAudienceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Workspace $workspace;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['jotter.auth_bypass' => false]);

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);
        $this->workspace = Workspace::create(['tenant_id' => $this->tenant->id, 'slug' => 'brain', 'name' => 'Brain', 'vault_path' => storage_path('app/vaults/aud_'.uniqid())]);
        $this->user = User::factory()->create(['is_admin' => false]);
        Membership::create(['tenant_id' => $this->tenant->id, 'workspace_id' => $this->workspace->id, 'subject_id' => (string) $this->user->id, 'role' => 'editor']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function providers(): array
    {
        return ['local' => ['local'], 'oidc' => ['oidc'], 'grandpasson' => ['grandpasson']];
    }

    #[DataProvider('providers')]
    public function test_machine_token_works_on_mcp_with_every_identity_provider(string $provider): void
    {
        config(['jotter.auth_provider' => $provider]);
        $this->app->forgetInstance(IdentityProvider::class);
        $this->assertInstanceOf(CredentialScopedIdentityProvider::class, app(IdentityProvider::class));

        $plain = app(MachineTokenIssuer::class)->issue($this->user, $this->tenant, 'aud', MachineTokenOptions::readOnly())['plain'];

        $response = $this->withToken($plain)
            ->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'list_workspaces', 'arguments' => []]])
            ->assertOk();

        $workspaces = json_decode((string) $response->json('result.content.0.text'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['brain'], array_column($workspaces, 'slug'));
    }

    public function test_grandpasson_service_token_without_kb_read_is_refused_on_mcp(): void
    {
        $this->bindServiceToken(['kb:write']);
        $this->withToken('svc-token')
            ->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])
            ->assertStatus(403);
    }

    public function test_grandpasson_service_token_with_kb_read_uses_mcp(): void
    {
        $this->bindServiceToken(['kb:read']);
        $this->withToken('svc-token')
            ->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])
            ->assertOk();
    }

    /**
     * @param list<string> $scopes
     */
    private function bindServiceToken(array $scopes): void
    {
        config(['jotter.auth_provider' => 'grandpasson', 'jotter.grandpasson_resource.inbound_enabled' => true]);
        $result = new IntrospectionResult(active: true, scopes: $scopes, audiences: ["workspace/{$this->workspace->id}"], clientId: 'svc-acme');
        $this->app->singleton(IdentityProvider::class, fn () => new CredentialScopedIdentityProvider(
            new GrandpaSSOnIdentityProvider(new FakeIntrospectionClient($result))
        ));
    }
}
