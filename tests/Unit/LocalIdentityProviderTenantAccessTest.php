<?php

namespace Tests\Unit;

use App\Domain\Auth\AuthenticatedSubject;
use App\Domain\Auth\Providers\LocalIdentityProvider;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocalIdentityProviderTenantAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_gets_null_meaning_unrestricted(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $subject = new AuthenticatedSubject(
            subjectId: (string) $admin->id,
            email: $admin->email,
            name: $admin->name,
            isAdmin: true,
            user: $admin,
        );

        $provider = new LocalIdentityProvider();

        $this->assertNull($provider->accessibleTenantIds($subject));
    }

    public function test_non_admin_sees_tenant_from_direct_workspace_membership(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);
        $ws = Workspace::create(['tenant_id' => $tenant->id, 'slug' => 'a', 'name' => 'A', 'vault_path' => storage_path('app/vaults/tenant_access_a')]);

        Membership::create([
            'subject_id' => (string) $user->id,
            'tenant_id' => $tenant->id,
            'workspace_id' => $ws->id,
            'role' => 'editor',
        ]);

        $subject = new AuthenticatedSubject(
            subjectId: (string) $user->id,
            email: $user->email,
            name: $user->name,
            isAdmin: false,
            user: $user,
        );

        $provider = new LocalIdentityProvider();

        $this->assertEqualsCanonicalizing([$tenant->id], $provider->accessibleTenantIds($subject));
    }

    public function test_non_admin_sees_tenant_from_tenant_wide_membership(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);

        Membership::create([
            'subject_id' => (string) $user->id,
            'tenant_id' => $tenant->id,
            'workspace_id' => null,
            'role' => 'viewer',
        ]);

        $subject = new AuthenticatedSubject(
            subjectId: (string) $user->id,
            email: $user->email,
            name: $user->name,
            isAdmin: false,
            user: $user,
        );

        $provider = new LocalIdentityProvider();

        $this->assertEqualsCanonicalizing([$tenant->id], $provider->accessibleTenantIds($subject));
    }

    public function test_non_admin_sees_multiple_distinct_tenants(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $tenantA = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);
        $tenantB = Tenant::create(['slug' => 'globex', 'name' => 'Globex']);
        $wsA = Workspace::create(['tenant_id' => $tenantA->id, 'slug' => 'a', 'name' => 'A', 'vault_path' => storage_path('app/vaults/tenant_access_multi_a')]);

        Membership::create(['subject_id' => (string) $user->id, 'tenant_id' => $tenantA->id, 'workspace_id' => $wsA->id, 'role' => 'editor']);
        Membership::create(['subject_id' => (string) $user->id, 'tenant_id' => $tenantB->id, 'workspace_id' => null, 'role' => 'viewer']);

        $subject = new AuthenticatedSubject(
            subjectId: (string) $user->id,
            email: $user->email,
            name: $user->name,
            isAdmin: false,
            user: $user,
        );

        $provider = new LocalIdentityProvider();

        $this->assertEqualsCanonicalizing([$tenantA->id, $tenantB->id], $provider->accessibleTenantIds($subject));
    }

    public function test_user_with_no_membership_sees_no_tenants(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $subject = new AuthenticatedSubject(
            subjectId: (string) $user->id,
            email: $user->email,
            name: $user->name,
            isAdmin: false,
            user: $user,
        );

        $provider = new LocalIdentityProvider();

        $this->assertSame([], $provider->accessibleTenantIds($subject));
    }
}
