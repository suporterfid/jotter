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

class LocalIdentityProviderWorkspaceAccessTest extends TestCase
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

        $this->assertNull($provider->accessibleWorkspaceIds($subject));
    }

    public function test_non_admin_sees_only_directly_assigned_workspace(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);
        $wsA = Workspace::create(['tenant_id' => $tenant->id, 'slug' => 'a', 'name' => 'A', 'vault_path' => storage_path('app/vaults/ws_a')]);
        $wsB = Workspace::create(['tenant_id' => $tenant->id, 'slug' => 'b', 'name' => 'B', 'vault_path' => storage_path('app/vaults/ws_b')]);

        Membership::create([
            'subject_id' => (string) $user->id,
            'tenant_id' => $tenant->id,
            'workspace_id' => $wsA->id,
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
        $ids = $provider->accessibleWorkspaceIds($subject);

        $this->assertIsArray($ids);
        $this->assertEqualsCanonicalizing([$wsA->id], $ids);
    }

    public function test_tenant_wide_membership_sees_every_workspace_in_tenant(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);
        $wsA = Workspace::create(['tenant_id' => $tenant->id, 'slug' => 'a', 'name' => 'A', 'vault_path' => storage_path('app/vaults/ws_a2')]);
        $wsB = Workspace::create(['tenant_id' => $tenant->id, 'slug' => 'b', 'name' => 'B', 'vault_path' => storage_path('app/vaults/ws_b2')]);

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
        $ids = $provider->accessibleWorkspaceIds($subject);

        $this->assertEqualsCanonicalizing([$wsA->id, $wsB->id], $ids);
    }

    public function test_user_with_no_membership_sees_nothing(): void
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

        $this->assertSame([], $provider->accessibleWorkspaceIds($subject));
    }
}
