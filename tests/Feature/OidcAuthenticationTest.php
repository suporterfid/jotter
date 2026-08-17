<?php

namespace Tests\Feature;

use App\Domain\Auth\Oidc\OidcClaims;
use App\Domain\Auth\Oidc\OidcClientInterface;
use App\Domain\Auth\Oidc\OidcProtocolException;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class OidcAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'jotter.auth_provider' => 'oidc',
            'jotter.oidc' => [
                'issuer_url' => 'https://issuer.example.test',
                'client_id' => 'jotter-client',
                'client_secret' => 'secret',
                'redirect_uri' => 'https://jotter.example.test/api/auth/oidc/callback',
                'scopes' => ['openid', 'profile', 'email'],
                'post_login_redirect_uri' => 'https://jotter.example.test',
                'allow_insecure_http' => false,
                'trusted_email_claim' => false,
                'configured' => true,
            ],
        ]);
        $this->app->singleton(OidcClientInterface::class, fn (): FakeFeatureOidcClient => new FakeFeatureOidcClient);
        $this->app->forgetInstance(\App\Domain\Auth\Contracts\IdentityProvider::class);
    }

    public function test_oidc_redirect_uses_the_public_authorization_endpoint(): void
    {
        $response = $this->get('/api/auth/oidc/redirect');

        $response->assertRedirect('https://issuer.example.test/authorize?provider=fake');
    }

    public function test_successful_callback_logs_in_and_resolves_me(): void
    {
        $response = $this->get('/api/auth/oidc/callback?code=one-time-code&state=state');

        $response->assertRedirect('https://jotter.example.test');
        $this->assertAuthenticated();
        $this->assertTrue((bool) session('oidc_authenticated'));
        $this->assertDatabaseHas('users', ['email' => 'person@example.test', 'is_admin' => false]);
        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'person@example.test');
    }

    public function test_new_oidc_user_has_no_workspace_access_until_granted_membership(): void
    {
        $this->get('/api/auth/oidc/callback?code=one-time-code&state=state')->assertRedirect();
        $tenant = Tenant::create(['slug' => 'default', 'name' => 'Default']);
        $workspace = Workspace::create([
            'tenant_id' => $tenant->id,
            'slug' => 'main',
            'name' => 'Main',
            'vault_path' => storage_path('app/vaults/oidc-test'),
        ]);

        $this->getJson("/api/workspaces/{$workspace->id}/notes")->assertForbidden();
    }

    public function test_password_login_cannot_be_used_as_an_oidc_bypass(): void
    {
        User::factory()->create(['email' => 'person@example.test', 'password' => bcrypt('anything')]);

        $this->postJson('/api/auth/login', [
            'email' => 'person@example.test',
            'password' => 'anything',
        ])->assertUnauthorized();
    }

    public function test_auth_config_exposes_the_oidc_redirect(): void
    {
        $this->getJson('/api/auth/config')
            ->assertOk()
            ->assertJsonPath('data.provider', 'oidc')
            ->assertJsonPath('data.sso_login_url', url('/api/auth/oidc/redirect'));
    }

    public function test_provider_failure_redirects_generically_without_provisioning(): void
    {
        $this->app->singleton(OidcClientInterface::class, fn (): FakeFeatureOidcClient => new FakeFeatureOidcClient(fails: true));

        $this->get('/api/auth/oidc/callback?error=access_denied')
            ->assertRedirect('https://jotter.example.test?auth_error=oidc_failed');
        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'person@example.test']);
    }
}

final class FakeFeatureOidcClient implements OidcClientInterface
{
    public function __construct(private readonly bool $fails = false)
    {
    }

    public function authorizationUrl(Request $request): string
    {
        $request->session()->put('oidc.fake_started', true);

        return 'https://issuer.example.test/authorize?provider=fake';
    }

    public function authenticateCallback(Request $request): OidcClaims
    {
        if ($this->fails) {
            throw new OidcProtocolException('fake provider failure');
        }

        return new OidcClaims(
            issuer: 'https://issuer.example.test/',
            subject: 'subject-123',
            email: 'person@example.test',
            emailVerified: true,
            name: 'Person Example',
            locale: 'en',
        );
    }
}
