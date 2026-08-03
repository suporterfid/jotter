<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admin_only_sees_workspaces_they_have_membership_for(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);
        $wsA = Workspace::create(['tenant_id' => $tenant->id, 'slug' => 'a', 'name' => 'A', 'vault_path' => storage_path('app/vaults/idx_a')]);
        $wsB = Workspace::create(['tenant_id' => $tenant->id, 'slug' => 'b', 'name' => 'B', 'vault_path' => storage_path('app/vaults/idx_b')]);

        Membership::create([
            'subject_id' => (string) $user->id,
            'tenant_id' => $tenant->id,
            'workspace_id' => $wsA->id,
            'role' => 'editor',
        ]);

        $res = $this->actingAs($user)->getJson('/api/workspaces');

        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$wsA->id], $ids);
    }

    public function test_admin_sees_all_workspaces(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);
        $wsA = Workspace::create(['tenant_id' => $tenant->id, 'slug' => 'a', 'name' => 'A', 'vault_path' => storage_path('app/vaults/idx_a2')]);
        $wsB = Workspace::create(['tenant_id' => $tenant->id, 'slug' => 'b', 'name' => 'B', 'vault_path' => storage_path('app/vaults/idx_b2')]);

        $res = $this->actingAs($admin)->getJson('/api/workspaces');

        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$wsA->id, $wsB->id], $ids);
    }

    public function test_user_with_no_membership_sees_empty_list(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        Tenant::create(['slug' => 'acme', 'name' => 'Acme']);

        $res = $this->actingAs($user)->getJson('/api/workspaces');

        $res->assertOk();
        $this->assertSame([], $res->json('data'));
    }

    public function test_tenant_id_param_scopes_workspaces_to_that_tenant(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $tenantA = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);
        $tenantB = Tenant::create(['slug' => 'globex', 'name' => 'Globex']);
        $wsA = Workspace::create(['tenant_id' => $tenantA->id, 'slug' => 'a', 'name' => 'A', 'vault_path' => storage_path('app/vaults/idx_tenant_a')]);
        $wsB = Workspace::create(['tenant_id' => $tenantB->id, 'slug' => 'b', 'name' => 'B', 'vault_path' => storage_path('app/vaults/idx_tenant_b')]);

        Membership::create(['subject_id' => (string) $user->id, 'tenant_id' => $tenantA->id, 'workspace_id' => null, 'role' => 'viewer']);
        Membership::create(['subject_id' => (string) $user->id, 'tenant_id' => $tenantB->id, 'workspace_id' => null, 'role' => 'viewer']);

        $res = $this->actingAs($user)->getJson('/api/workspaces?tenant_id='.$tenantA->id);

        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$wsA->id], $ids);
    }

    public function test_tenant_id_param_for_inaccessible_tenant_yields_empty_list(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $tenantA = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);
        $tenantB = Tenant::create(['slug' => 'globex', 'name' => 'Globex']);
        Workspace::create(['tenant_id' => $tenantB->id, 'slug' => 'b', 'name' => 'B', 'vault_path' => storage_path('app/vaults/idx_tenant_inaccessible')]);

        Membership::create(['subject_id' => (string) $user->id, 'tenant_id' => $tenantA->id, 'workspace_id' => null, 'role' => 'viewer']);

        $res = $this->actingAs($user)->getJson('/api/workspaces?tenant_id='.$tenantB->id);

        $res->assertOk();
        $this->assertSame([], $res->json('data'));
    }
}
