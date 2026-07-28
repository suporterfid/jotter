<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminMembershipManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admin_is_forbidden(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $tenant = Tenant::create(['slug' => 'default', 'name' => 'Default']);
        $workspace = Workspace::create([
            'tenant_id' => $tenant->id,
            'slug' => 'test',
            'name' => 'Test Workspace',
            'vault_path' => storage_path('app/vaults/test_'.uniqid()),
        ]);

        $res = $this->actingAs($user)->getJson("/api/admin/workspaces/{$workspace->id}/members");
        $res->assertStatus(403);
    }

    public function test_admin_can_grant_change_role_and_revoke_membership(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $tenant = Tenant::create(['slug' => 'default', 'name' => 'Default']);
        $workspace = Workspace::create([
            'tenant_id' => $tenant->id,
            'slug' => 'test',
            'name' => 'Test Workspace',
            'vault_path' => storage_path('app/vaults/test_'.uniqid()),
        ]);

        // Create initial owner
        $ownerMember = Membership::create([
            'tenant_id' => $tenant->id,
            'workspace_id' => $workspace->id,
            'subject_id' => 'user-owner',
            'role' => 'owner',
        ]);

        // 1. Grant membership
        $grantRes = $this->actingAs($admin)->postJson("/api/admin/workspaces/{$workspace->id}/members", [
            'subject_id' => 'user-editor',
            'role' => 'editor',
        ]);
        $grantRes->assertCreated();
        $memberId = $grantRes->json('data.id');

        $this->assertDatabaseHas('memberships', [
            'id' => $memberId,
            'role' => 'editor',
        ]);
        $this->assertDatabaseHas('audit_log', [
            'event' => 'membership.granted',
        ]);

        // 2. Change role
        $updateRes = $this->actingAs($admin)->putJson("/api/admin/workspaces/{$workspace->id}/members/{$memberId}", [
            'role' => 'admin',
        ]);
        $updateRes->assertOk();
        $this->assertDatabaseHas('memberships', [
            'id' => $memberId,
            'role' => 'admin',
        ]);
        $this->assertDatabaseHas('audit_log', [
            'event' => 'membership.updated',
        ]);

        // 3. Revoke membership
        $delRes = $this->actingAs($admin)->deleteJson("/api/admin/workspaces/{$workspace->id}/members/{$memberId}");
        $delRes->assertStatus(204);
        $this->assertDatabaseMissing('memberships', ['id' => $memberId]);
        $this->assertDatabaseHas('audit_log', [
            'event' => 'membership.revoked',
        ]);
    }

    public function test_last_owner_protection(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $tenant = Tenant::create(['slug' => 'default', 'name' => 'Default']);
        $workspace = Workspace::create([
            'tenant_id' => $tenant->id,
            'slug' => 'test',
            'name' => 'Test Workspace',
            'vault_path' => storage_path('app/vaults/test_'.uniqid()),
        ]);

        $onlyOwner = Membership::create([
            'tenant_id' => $tenant->id,
            'workspace_id' => $workspace->id,
            'subject_id' => 'sole-owner',
            'role' => 'owner',
        ]);

        // Attempting to demote sole owner fails
        $demoteRes = $this->actingAs($admin)->putJson("/api/admin/workspaces/{$workspace->id}/members/{$onlyOwner->id}", [
            'role' => 'editor',
        ]);
        $demoteRes->assertStatus(422);

        // Attempting to delete sole owner fails
        $deleteRes = $this->actingAs($admin)->deleteJson("/api/admin/workspaces/{$workspace->id}/members/{$onlyOwner->id}");
        $deleteRes->assertStatus(422);
    }
}
