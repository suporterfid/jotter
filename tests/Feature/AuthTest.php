<?php

namespace Tests\Feature;

use App\Domain\Auth\AuthenticatedSubject;
use App\Domain\Auth\Contracts\IdentityProvider;
use App\Domain\Auth\Providers\GrandpaSSOnIdentityProvider;
use App\Domain\Auth\Providers\LocalIdentityProvider;
use App\Models\AuditLog;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['jotter.auth_bypass' => false]);
        config(['jotter.auth_provider' => 'local']);
    }

    public function test_bootstrap_admin_command_creates_administrator_user(): void
    {
        $exitCode = Artisan::call('platform:bootstrap-admin', [
            'email' => 'admin@example.com',
            'password' => 'secret-password-123',
        ]);

        $this->assertSame(0, $exitCode);

        /** @var User $user */
        $user = User::query()->where('email', 'admin@example.com')->firstOrFail();
        $this->assertTrue($user->is_admin);
        $this->assertTrue(Hash::check('secret-password-123', $user->password));
    }

    public function test_login_endpoint_authenticates_valid_credentials_and_writes_audit_log(): void
    {
        $user = User::factory()->create([
            'email' => 'user@example.com',
            'password' => Hash::make('password12345'),
            'is_admin' => true,
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'user@example.com',
            'password' => 'password12345',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.email', 'user@example.com')
            ->assertJsonPath('data.is_admin', true);

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('audit_log', [
            'actor_subject_id' => (string) $user->id,
            'event' => \App\Domain\Audit\AuditEvent::AUTH_LOGIN_SUCCESS->value,
        ]);
    }

    public function test_login_endpoint_rejects_invalid_password_and_writes_audit_log(): void
    {
        User::factory()->create([
            'email' => 'user@example.com',
            'password' => Hash::make('correct-password'),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'user@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertUnauthorized()
            ->assertJson(['message' => 'Invalid email or password.']);

        $this->assertGuest();
        $this->assertDatabaseHas('audit_log', [
            'event' => \App\Domain\Audit\AuditEvent::AUTH_LOGIN_FAILURE->value,
        ]);
    }

    public function test_unauthenticated_requests_to_protected_routes_return_401_and_audit(): void
    {
        $tenant = Tenant::create(['slug' => 'default', 'name' => 'Default']);
        $workspace = Workspace::create([
            'tenant_id' => $tenant->id,
            'slug' => 'main',
            'name' => 'Main',
            'vault_path' => storage_path('app/vaults/test'),
        ]);

        $this->getJson("/api/workspaces/{$workspace->id}/notes")
            ->assertUnauthorized()
            ->assertJson(['message' => 'Unauthenticated.']);

        $this->assertDatabaseHas('audit_log', [
            'event' => \App\Domain\Audit\AuditEvent::AUTH_UNAUTHORIZED->value,
            'actor_subject_id' => null,
        ]);
    }

    public function test_authenticated_admin_can_access_workspace_notes(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $tenant = Tenant::create(['slug' => 'default', 'name' => 'Default']);
        $workspace = Workspace::create([
            'tenant_id' => $tenant->id,
            'slug' => 'main',
            'name' => 'Main',
            'vault_path' => storage_path('app/vaults/test'),
        ]);

        $this->actingAs($admin)
            ->getJson("/api/workspaces/{$workspace->id}/notes")
            ->assertOk();
    }

    public function test_authenticated_non_member_user_is_forbidden_403_and_audited(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $tenant = Tenant::create(['slug' => 'default', 'name' => 'Default']);
        $workspace = Workspace::create([
            'tenant_id' => $tenant->id,
            'slug' => 'main',
            'name' => 'Main',
            'vault_path' => storage_path('app/vaults/test'),
        ]);

        $this->actingAs($user)
            ->getJson("/api/workspaces/{$workspace->id}/notes")
            ->assertForbidden()
            ->assertJson(['message' => 'Forbidden workspace access.']);

        $this->assertDatabaseHas('audit_log', [
            'tenant_id' => $tenant->id,
            'workspace_id' => $workspace->id,
            'actor_subject_id' => (string) $user->id,
            'event' => \App\Domain\Audit\AuditEvent::AUTH_FORBIDDEN->value,
        ]);
    }

    public function test_authenticated_member_user_can_access_their_workspace(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $tenant = Tenant::create(['slug' => 'default', 'name' => 'Default']);
        $workspace = Workspace::create([
            'tenant_id' => $tenant->id,
            'slug' => 'main',
            'name' => 'Main',
            'vault_path' => storage_path('app/vaults/test'),
        ]);

        Membership::create([
            'subject_id' => (string) $user->id,
            'tenant_id' => $tenant->id,
            'workspace_id' => $workspace->id,
            'role' => 'editor',
        ]);

        $this->actingAs($user)
            ->getJson("/api/workspaces/{$workspace->id}/notes")
            ->assertOk();
    }

    public function test_grandpasson_auth_provider_fails_closed(): void
    {
        config(['jotter.auth_provider' => 'grandpasson']);
        $provider = app(IdentityProvider::class);

        $this->assertInstanceOf(GrandpaSSOnIdentityProvider::class, $provider);

        $admin = User::factory()->create(['is_admin' => true]);
        $tenant = Tenant::create(['slug' => 'default', 'name' => 'Default']);
        $workspace = Workspace::create([
            'tenant_id' => $tenant->id,
            'slug' => 'main',
            'name' => 'Main',
            'vault_path' => storage_path('app/vaults/test'),
        ]);

        $this->actingAs($admin)
            ->getJson("/api/workspaces/{$workspace->id}/notes")
            ->assertUnauthorized();
    }

    public function test_grandpasson_auth_provider_still_accepts_real_local_login(): void
    {
        // #231: switching to the grandpasson provider must not lock out
        // existing local accounts (e.g. the bootstrap admin). authenticate()
        // delegates to LocalIdentityProvider and flags the session so
        // resolveIdentity() recognizes it on subsequent requests -- verify
        // that end-to-end through the real /api/auth/login endpoint, not
        // just via actingAs() (which bypasses session state entirely and is
        // why the fails-closed test above correctly 401s).
        config(['jotter.auth_provider' => 'grandpasson']);

        $user = User::factory()->create([
            'email' => 'admin@example.com',
            'password' => Hash::make('password12345'),
            'is_admin' => true,
        ]);
        $tenant = Tenant::create(['slug' => 'default', 'name' => 'Default']);
        $workspace = Workspace::create([
            'tenant_id' => $tenant->id,
            'slug' => 'main',
            'name' => 'Main',
            'vault_path' => storage_path('app/vaults/test'),
        ]);

        $this->postJson('/api/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'password12345',
        ])->assertOk();

        $this->getJson("/api/workspaces/{$workspace->id}/notes")->assertOk();
        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('data.email', 'admin@example.com');
    }

    public function test_promote_admin_command_flips_is_admin_for_existing_user(): void
    {
        $user = User::factory()->create(['email' => 'sso-user@example.com', 'is_admin' => false]);

        $exitCode = Artisan::call('platform:promote-admin', ['email' => 'sso-user@example.com']);

        $this->assertSame(0, $exitCode);
        $this->assertTrue($user->fresh()->is_admin);
    }

    public function test_promote_admin_command_is_idempotent_for_an_existing_admin(): void
    {
        User::factory()->create(['email' => 'already-admin@example.com', 'is_admin' => true]);

        $exitCode = Artisan::call('platform:promote-admin', ['email' => 'already-admin@example.com']);

        $this->assertSame(0, $exitCode);
    }

    public function test_promote_admin_command_fails_for_unknown_email(): void
    {
        $exitCode = Artisan::call('platform:promote-admin', ['email' => 'nobody@example.com']);

        $this->assertSame(1, $exitCode);
    }

    public function test_auth_config_endpoint_reports_local_provider_with_no_sso_url(): void
    {
        $this->getJson('/api/auth/config')
            ->assertOk()
            ->assertJson(['data' => ['provider' => 'local', 'sso_login_url' => null]]);
    }

    public function test_auth_config_endpoint_reports_grandpasson_provider_and_builds_sso_login_url(): void
    {
        config([
            'jotter.auth_provider' => 'grandpasson',
            'jotter.sso.broker_base_url' => 'https://hub.taskconnect.com.br/sso',
            'jotter.sso.client_id' => 'jotter',
            'app.url' => 'https://hub.taskconnect.com.br',
        ]);

        $response = $this->getJson('/api/auth/config')->assertOk();

        $response->assertJsonPath('data.provider', 'grandpasson');
        $url = $response->json('data.sso_login_url');
        $this->assertStringStartsWith('https://hub.taskconnect.com.br/sso/login/email?', $url);
        $this->assertStringContainsString('client_id=jotter', $url);
        $this->assertStringContainsString('redirect_uri=https%3A%2F%2Fhub.taskconnect.com.br', $url);
        $this->assertStringContainsString('state=', $url);
    }

    public function test_auth_config_endpoint_omits_sso_url_when_grandpasson_client_not_configured(): void
    {
        config([
            'jotter.auth_provider' => 'grandpasson',
            'jotter.sso.broker_base_url' => null,
            'jotter.sso.client_id' => null,
        ]);

        $this->getJson('/api/auth/config')
            ->assertOk()
            ->assertJson(['data' => ['provider' => 'grandpasson', 'sso_login_url' => null]]);
    }

    public function test_auth_config_endpoint_reports_version_from_version_file(): void
    {
        $versionFile = base_path('VERSION');
        file_put_contents($versionFile, "abc1234 · 2026-08-01 16:30\n");

        try {
            $this->getJson('/api/auth/config')
                ->assertOk()
                ->assertJsonPath('data.version', 'abc1234 · 2026-08-01 16:30');
        } finally {
            unlink($versionFile);
        }
    }

    public function test_auth_config_endpoint_reports_null_version_when_version_file_absent(): void
    {
        $versionFile = base_path('VERSION');
        if (file_exists($versionFile)) {
            unlink($versionFile);
        }

        $this->getJson('/api/auth/config')
            ->assertOk()
            ->assertJsonPath('data.version', null);
    }

    public function test_logout_endpoint_invalidates_session_and_writes_audit_log(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/auth/logout')
            ->assertOk()
            ->assertJson(['message' => 'Logged out successfully.']);

        $this->assertGuest();
        $this->assertDatabaseHas('audit_log', [
            'actor_subject_id' => (string) $user->id,
            'event' => 'auth.logout',
        ]);
    }

    public function test_auth_me_endpoint_returns_current_authenticated_user(): void
    {
        $user = User::factory()->create(['email' => 'me@example.com', 'is_admin' => true]);

        $this->actingAs($user)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'me@example.com')
            ->assertJsonPath('data.is_admin', true);
    }
}
