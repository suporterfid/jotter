<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\OAuthGrant;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\OAuthFlow;
use Tests\TestCase;

final class OAuthRefreshRotationTest extends TestCase
{
    use OAuthFlow;
    use RefreshDatabase;

    private Workspace $brain;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['jotter.auth_bypass' => false, 'jotter.auth_provider' => 'local', 'jotter.oauth.enabled' => true, 'app.url' => 'https://brain.example.test']);
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);
        $this->brain = Workspace::create(['tenant_id' => $tenant->id, 'slug' => 'brain', 'name' => 'Brain', 'vault_path' => storage_path('app/vaults/oauth_rt_'.uniqid())]);
        $this->user = User::factory()->create(['is_admin' => false]);
        Membership::create(['tenant_id' => $tenant->id, 'workspace_id' => $this->brain->id, 'subject_id' => (string) $this->user->id, 'role' => 'viewer']);
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function connect(): array
    {
        $client = $this->registerClient();
        $consent = $this->actingAs($this->user)->get($this->authorizeUrl($client['client_id']))->assertOk();
        $code = $this->approve($this->consentRequestId($consent), [$this->brain->id])['code'];

        return [$client, $this->exchange($client['client_id'], $code)->assertOk()->json()];
    }

    private function refresh(string $clientId, string $refreshToken)
    {
        return $this->post('/oauth/token', ['grant_type' => 'refresh_token', 'client_id' => $clientId, 'refresh_token' => $refreshToken]);
    }

    public function test_refresh_rotates_and_reuse_revokes_the_whole_grant(): void
    {
        [$client, $first] = $this->connect();

        $second = $this->refresh($client['client_id'], $first['refresh_token'])->assertOk()->json();
        $this->assertNotSame($first['refresh_token'], $second['refresh_token']);
        $this->assertNotSame($first['access_token'], $second['access_token']);
        $this->mcpPing($second['access_token'])->assertOk();

        // Replaying the rotated refresh token is treated as theft.
        $this->refresh($client['client_id'], $first['refresh_token'])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        $this->assertNotNull(OAuthGrant::query()->sole()->revoked_at);
        $this->mcpPing($second['access_token'])->assertStatus(401);
        $this->refresh($client['client_id'], $second['refresh_token'])->assertStatus(400);
    }

    public function test_refresh_by_another_client_and_expired_access_tokens_fail(): void
    {
        [, $tokens] = $this->connect();
        $other = $this->registerClient(name: 'Other');

        $this->refresh($other['client_id'], $tokens['refresh_token'])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        $this->post('/oauth/token', ['grant_type' => 'password', 'client_id' => $other['client_id']])->assertStatus(400)->assertJsonPath('error', 'unsupported_grant_type');
        $this->post('/oauth/token', ['grant_type' => 'refresh_token', 'client_id' => 'jtc_nope', 'refresh_token' => 'x'])->assertStatus(401)->assertJsonPath('error', 'invalid_client');

        $this->travel(61)->minutes();
        $this->mcpPing($tokens['access_token'])->assertStatus(401);
    }

    public function test_revocation_endpoint_and_connected_apps(): void
    {
        [$client, $tokens] = $this->connect();

        $list = $this->actingAs($this->user)->getJson('/api/user/oauth-grants')->assertOk()->json('data');
        $this->assertCount(1, $list);
        $this->assertSame('Claude', $list[0]['client_name']);
        $this->assertSame(['brain'], $list[0]['workspaces']);
        $this->assertSame(['claude.ai'], $list[0]['redirect_hosts']);

        // Tokens never manage grants.
        $this->withToken($tokens['access_token'])->getJson('/api/user/oauth-grants')->assertStatus(403);
        $this->flushHeaders();

        $this->post('/oauth/revoke', ['client_id' => $client['client_id'], 'token' => $tokens['refresh_token']])->assertOk();
        $this->mcpPing($tokens['access_token'])->assertStatus(401);
        $this->assertSame([], $this->actingAs($this->user)->getJson('/api/user/oauth-grants')->json('data'));

        // Unknown tokens are accepted silently (RFC 7009).
        $this->post('/oauth/revoke', ['client_id' => $client['client_id'], 'token' => 'jt_ort_unknown'])->assertOk();
    }

    public function test_user_and_admin_can_revoke_from_connected_apps(): void
    {
        [, $tokens] = $this->connect();
        $grant = OAuthGrant::query()->sole();
        $stranger = User::factory()->create(['is_admin' => false]);

        $this->actingAs($stranger)->deleteJson("/api/user/oauth-grants/{$grant->id}")->assertNotFound();
        $this->actingAs($stranger)->getJson('/api/admin/oauth-grants')->assertForbidden();

        $admin = User::factory()->create(['is_admin' => true]);
        $this->assertCount(1, $this->actingAs($admin)->getJson('/api/admin/oauth-grants')->assertOk()->json('data'));
        $this->actingAs($admin)->deleteJson("/api/admin/oauth-grants/{$grant->id}")->assertOk();

        $this->mcpPing($tokens['access_token'])->assertStatus(401);
        $this->assertDatabaseHas('audit_log', ['event' => 'oauth.grant_revoked']);
    }
}
