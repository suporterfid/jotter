<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\MachineToken;
use App\Models\Membership;
use App\Models\OAuthGrant;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\OAuthFlow;
use Tests\TestCase;

/**
 * End-to-end authorization code + PKCE flow as Claude web or ChatGPT run it:
 * DCR → authorize (login, consent) → token → MCP with the access token.
 */
final class OAuthPkceFlowTest extends TestCase
{
    use OAuthFlow;
    use RefreshDatabase;

    private Tenant $tenant;
    private Workspace $brain;
    private Workspace $restricted;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['jotter.auth_bypass' => false, 'jotter.auth_provider' => 'local', 'jotter.oauth.enabled' => true, 'app.url' => 'https://brain.example.test']);

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);
        $this->brain = Workspace::create(['tenant_id' => $this->tenant->id, 'slug' => 'brain', 'name' => 'Brain', 'vault_path' => storage_path('app/vaults/oauth_b_'.uniqid())]);
        $this->restricted = Workspace::create(['tenant_id' => $this->tenant->id, 'slug' => 'brain-restrito', 'name' => 'Restrito', 'vault_path' => storage_path('app/vaults/oauth_r_'.uniqid())]);
        $this->user = User::factory()->create(['is_admin' => false, 'email' => 'joe@acme.test']);
        foreach ([$this->brain, $this->restricted] as $workspace) {
            Membership::create(['tenant_id' => $this->tenant->id, 'workspace_id' => $workspace->id, 'subject_id' => (string) $this->user->id, 'role' => 'editor']);
        }
    }

    public function test_full_flow_issues_a_token_scoped_to_the_consented_workspace(): void
    {
        $client = $this->registerClient();
        $this->assertStringStartsWith('jtc_', $client['client_id']);
        $this->assertArrayNotHasKey('client_secret', $client);

        // Not signed in: sent to the SPA login with a way back.
        $url = $this->authorizeUrl($client['client_id']);
        $redirect = $this->get($url)->assertRedirect();
        $this->assertStringContainsString('/?login=1&return_to=%2Foauth%2Fauthorize%3F', (string) $redirect->headers->get('Location'));

        $consent = $this->actingAs($this->user)->get($url)->assertOk();
        $consent->assertSee('Claude')->assertSee('claude.ai')->assertSee('brain-restrito')->assertHeader('X-Frame-Options', 'DENY');
        $this->assertStringNotContainsString('checked data-testid="oauth-ws-', (string) $consent->getContent(), 'no workspace is pre-selected');

        $query = $this->approve($this->consentRequestId($consent), [$this->brain->id], write: true);
        $this->assertSame('state-123', $query['state']);
        $this->assertSame('https://brain.example.test', $query['iss']);

        $tokens = $this->exchange($client['client_id'], $query['code'])->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame('Bearer', $tokens->json('token_type'));
        $this->assertStringStartsWith('jt_oat_', $tokens->json('access_token'));
        $this->assertStringStartsWith('jt_ort_', $tokens->json('refresh_token'));
        $this->assertSame('mcp:read mcp:write', $tokens->json('scope'));
        $this->assertLessThanOrEqual(3600, $tokens->json('expires_in'));

        $access = (string) $tokens->json('access_token');
        $this->mcpPing($access)->assertOk();
        $listed = $this->mcp($access, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'list_workspaces', 'arguments' => []]])->assertOk();
        $this->assertSame(['brain'], array_column(json_decode((string) $listed->json('result.content.0.text'), true), 'slug'));
        $tools = array_column($this->mcp($access, ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/list'])->json('result.tools'), 'name');
        $this->assertContains('append_to_note', $tools);

        $token = MachineToken::query()->where('kind', 'oauth')->sole();
        $this->assertSame('claude', $token->client_slug);
        $this->assertSame([$this->brain->id], $token->workspace_ids);
        $this->assertSame(1, AuditLog::query()->where('event', 'oauth.grant_created')->count());

        // OAuth access tokens never reach the REST API (decision D8).
        $this->withToken($access)->getJson("/api/workspaces/{$this->brain->id}/notes")->assertStatus(403);
    }

    public function test_pkce_verifier_must_match_and_codes_are_single_use(): void
    {
        $client = $this->registerClient();
        $consent = $this->actingAs($this->user)->get($this->authorizeUrl($client['client_id']))->assertOk();
        $code = $this->approve($this->consentRequestId($consent), [$this->brain->id])['code'];

        $this->exchange($client['client_id'], $code, 'wrong-verifier-wrong-verifier-wrong-verifier-123')
            ->assertStatus(400)->assertJsonPath('error', 'invalid_grant');

        // The failed attempt burned the code; replaying it revokes the grant.
        $this->exchange($client['client_id'], $code)->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        $this->assertNotNull(OAuthGrant::query()->sole()->revoked_at);
    }

    public function test_code_replay_after_success_revokes_the_issued_tokens(): void
    {
        $client = $this->registerClient();
        $consent = $this->actingAs($this->user)->get($this->authorizeUrl($client['client_id']))->assertOk();
        $code = $this->approve($this->consentRequestId($consent), [$this->brain->id])['code'];
        $access = (string) $this->exchange($client['client_id'], $code)->assertOk()->json('access_token');
        $this->mcpPing($access)->assertOk();

        $this->exchange($client['client_id'], $code)->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        $this->mcpPing($access)->assertStatus(401);
    }

    public function test_authorize_rejects_bad_requests_safely(): void
    {
        $client = $this->registerClient();
        $this->actingAs($this->user);

        // Unregistered redirect: error page, nothing sent to the attacker URL.
        $this->get($this->authorizeUrl($client['client_id'], ['redirect_uri' => 'https://evil.example/cb']))->assertStatus(400);
        $this->get($this->authorizeUrl('jtc_unknown'))->assertStatus(400);

        // Verified redirect: errors go back to the client.
        $location = (string) $this->get($this->authorizeUrl($client['client_id'], ['code_challenge_method' => 'plain']))->assertRedirect()->headers->get('Location');
        $this->assertStringContainsString('error=invalid_request', $location);
        $location = (string) $this->get($this->authorizeUrl($client['client_id'], ['resource' => 'https://other.example/api/mcp']))->assertRedirect()->headers->get('Location');
        $this->assertStringContainsString('error=invalid_target', $location);
    }

    public function test_deny_and_validation_errors(): void
    {
        $client = $this->registerClient();
        $consent = $this->actingAs($this->user)->get($this->authorizeUrl($client['client_id']))->assertOk();
        $requestId = $this->consentRequestId($consent);

        // No workspace chosen: the form comes back with an error.
        $this->post('/oauth/authorize', ['request_id' => $requestId, 'decision' => 'approve', 'expires' => '90d'])->assertStatus(422)->assertSee('workspace');
        // Write path into _brain/ is refused.
        $this->post('/oauth/authorize', ['request_id' => $requestId, 'decision' => 'approve', 'workspace_ids' => [$this->brain->id], 'write' => '1', 'write_paths' => '_brain/**:update', 'client_slug' => 'claude', 'expires' => '90d'])->assertStatus(422);
        $this->assertSame(0, OAuthGrant::query()->count());

        $location = (string) $this->post('/oauth/authorize', ['request_id' => $requestId, 'decision' => 'deny'])->assertRedirect()->headers->get('Location');
        $this->assertStringContainsString('error=access_denied', $location);
        $this->assertStringContainsString('state=state-123', $location);
    }

    public function test_bearer_callers_cannot_drive_the_consent_screen(): void
    {
        $client = $this->registerClient();
        $this->withToken('jt_mkt_anything')->get($this->authorizeUrl($client['client_id']))->assertRedirect();
    }
}
