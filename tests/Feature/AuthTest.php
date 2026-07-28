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
