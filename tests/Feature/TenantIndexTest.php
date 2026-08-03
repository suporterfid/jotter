<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admin_only_sees_tenants_they_have_membership_for(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $tenantA = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);
        $tenantB = Tenant::create(['slug' => 'globex', 'name' => 'Globex']);

        Membership::create([
            'subject_id' => (string) $user->id,
            'tenant_id' => $tenantA->id,
            'workspace_id' => null,
            'role' => 'viewer',
        ]);

        $res = $this->actingAs($user)->getJson('/api/tenants');

        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$tenantA->id], $ids);
    }

    public function test_admin_sees_all_tenants(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $tenantA = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);
        $tenantB = Tenant::create(['slug' => 'globex', 'name' => 'Globex']);

        $res = $this->actingAs($admin)->getJson('/api/tenants');

        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$tenantA->id, $tenantB->id], $ids);
    }

    public function test_user_with_no_membership_sees_empty_tenant_list(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        Tenant::create(['slug' => 'acme', 'name' => 'Acme']);

        $res = $this->actingAs($user)->getJson('/api/tenants');

        $res->assertOk();
        $this->assertSame([], $res->json('data'));
    }
}
