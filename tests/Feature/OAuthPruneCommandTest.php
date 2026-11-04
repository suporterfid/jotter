<?php

namespace Tests\Feature;

use App\Models\MachineToken;
use App\Models\OAuthAuthCode;
use App\Models\OAuthClient;
use App\Models\OAuthGrant;
use App\Models\OAuthRefreshToken;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class OAuthPruneCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_prune_removes_expired_material_and_idle_clients_only(): void
    {
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);
        $active = OAuthClient::create(['client_id' => 'jtc_active', 'name' => 'Active', 'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'], 'last_used_at' => now()->subDays(60)]);
        $idle = OAuthClient::create(['client_id' => 'jtc_idle', 'name' => 'Idle', 'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'], 'last_used_at' => now()->subDays(60)]);
        $fresh = OAuthClient::create(['client_id' => 'jtc_fresh', 'name' => 'Fresh', 'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback']]);

        $grant = OAuthGrant::create(['tenant_id' => $tenant->id, 'subject_id' => '1', 'oauth_client_id' => $active->id, 'workspace_ids' => [], 'scopes' => ['mcp:read']]);
        OAuthAuthCode::create(['code_hash' => str_repeat('a', 64), 'oauth_grant_id' => $grant->id, 'redirect_uri' => 'x', 'code_challenge' => 'c', 'expires_at' => now()->subDays(2)]);
        OAuthAuthCode::create(['code_hash' => str_repeat('b', 64), 'oauth_grant_id' => $grant->id, 'redirect_uri' => 'x', 'code_challenge' => 'c', 'expires_at' => now()->addMinute()]);
        OAuthRefreshToken::create(['token_hash' => str_repeat('c', 64), 'oauth_grant_id' => $grant->id, 'expires_at' => now()->subMinute()]);
        OAuthRefreshToken::create(['token_hash' => str_repeat('d', 64), 'oauth_grant_id' => $grant->id, 'expires_at' => now()->addDays(10)]);
        MachineToken::create(['tenant_id' => $tenant->id, 'subject_id' => '1', 'name' => 'old', 'kind' => 'oauth', 'token_hash' => str_repeat('e', 64), 'expires_at' => now()->subDays(2), 'oauth_grant_id' => $grant->id]);
        MachineToken::create(['tenant_id' => $tenant->id, 'subject_id' => '1', 'name' => 'manual', 'token_hash' => str_repeat('f', 64), 'expires_at' => now()->subDays(2)]);

        $this->assertSame(0, Artisan::call('oauth:prune', ['--json' => true]));
        $counts = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(['codes' => 1, 'refresh_tokens' => 1, 'access_tokens' => 1, 'revoked_grants' => 0, 'clients' => 1], $counts);
        $this->assertNotNull(OAuthClient::query()->find($active->id), 'a client with an active grant stays');
        $this->assertNull(OAuthClient::query()->find($idle->id));
        $this->assertNotNull(OAuthClient::query()->find($fresh->id));
        $this->assertSame(1, MachineToken::query()->where('kind', 'manual')->count(), 'manual tokens are not pruned');
    }
}
