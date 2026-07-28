<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class AdminUserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['jotter.auth_bypass' => false]);
        config(['jotter.auth_provider' => 'local']);
    }

    public function test_non_admin_cannot_reach_admin_user_endpoints(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $res = $this->actingAs($user)->getJson('/api/admin/users');
        $res->assertStatus(403);

        $createRes = $this->actingAs($user)->postJson('/api/admin/users', [
            'name' => 'New User',
            'email' => 'new@example.com',
            'password' => 'Password123!',
        ]);
        $createRes->assertStatus(403);
    }

    public function test_admin_can_create_user_and_new_user_can_login(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        // 1. Admin creates local user
        $createRes = $this->actingAs($admin)->postJson('/api/admin/users', [
            'name' => 'Member User',
            'email' => 'member@example.com',
            'password' => 'Password123!',
            'is_admin' => false,
        ]);
        $createRes->assertCreated();
        $this->assertDatabaseHas('users', ['email' => 'member@example.com', 'is_active' => true]);
        $this->assertDatabaseHas('audit_log', ['event' => 'user.created']);

        // 2. New user logs in
        $loginRes = $this->postJson('/api/auth/login', [
            'email' => 'member@example.com',
            'password' => 'Password123!',
        ]);
        $loginRes->assertOk();
    }

    public function test_deactivation_blocks_login_and_invalidates_session_immediately(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $member = User::factory()->create([
            'email' => 'member2@example.com',
            'password' => bcrypt('Password123!'),
            'is_active' => true,
        ]);

        // 1. Member logs in and obtains session
        $this->actingAs($member);

        // 2. Admin deactivates member
        $deactRes = $this->actingAs($admin)->postJson("/api/admin/users/{$member->id}/deactivate");
        $deactRes->assertOk();
        $this->assertDatabaseHas('users', ['id' => $member->id, 'is_active' => false]);
        $this->assertDatabaseHas('audit_log', ['event' => 'user.deactivated']);

        // 3. Login attempt by deactivated user is rejected
        $loginRes = $this->postJson('/api/auth/login', [
            'email' => 'member2@example.com',
            'password' => 'Password123!',
        ]);
        $loginRes->assertStatus(401);

        // 4. Reactivation restores access
        $reactRes = $this->actingAs($admin)->postJson("/api/admin/users/{$member->id}/reactivate");
        $reactRes->assertOk();
        $this->assertDatabaseHas('users', ['id' => $member->id, 'is_active' => true]);
        $this->assertDatabaseHas('audit_log', ['event' => 'user.reactivated']);
    }

    public function test_password_reset_and_self_service_change(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $member = User::factory()->create([
            'password' => bcrypt('OldPassword123!'),
            'is_active' => true,
        ]);

        // 1. Admin resets password
        $resetRes = $this->actingAs($admin)->postJson("/api/admin/users/{$member->id}/reset-password", [
            'new_password' => 'NewPassword123!',
        ]);
        $resetRes->assertOk();
        $this->assertDatabaseHas('audit_log', ['event' => 'user.password_reset']);

        // 2. Self-service password change (refresh member to pick up reset password)
        $member->refresh();
        $changeRes = $this->actingAs($member)->postJson('/api/auth/change-password', [
            'current_password' => 'NewPassword123!',
            'new_password' => 'ChangedPassword123!',
        ]);
        $changeRes->assertOk();
    }

    public function test_endpoints_rejected_when_provider_is_not_local(): void
    {
        Config::set('jotter.auth_provider', 'grandpasson');
        Config::set('jotter.auth_bypass', true);

        $admin = User::factory()->create(['is_admin' => true]);

        $res = $this->actingAs($admin)->postJson('/api/admin/users', [
            'name' => 'Test',
            'email' => 'test@example.com',
            'password' => 'Password123!',
        ]);
        $res->assertStatus(400);
    }
}
