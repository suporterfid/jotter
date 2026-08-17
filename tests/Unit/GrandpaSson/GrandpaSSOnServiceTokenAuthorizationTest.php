<?php

namespace Tests\Unit\GrandpaSson;

use App\Domain\Auth\AuthenticatedSubject;
use App\Domain\Auth\Providers\GrandpaSSOnIdentityProvider;
use App\Models\Tenant;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class GrandpaSSOnServiceTokenAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function serviceSubject(array $audiences): AuthenticatedSubject
    {
        return $this->serviceSubjectWithScopes($audiences, ['kb:read', 'kb:write']);
    }

    private function serviceSubjectWithScopes(array $audiences, array $scopes): AuthenticatedSubject
    {
        return new AuthenticatedSubject(
            subjectId: 'service:svc-acme-integration',
            email: '',
            name: 'Service client svc-acme-integration',
            isAdmin: false,
            user: null,
            attributes: [
                'auth_method' => 'grandpasson_service_token',
                'scopes' => $scopes,
                'audiences' => $audiences,
            ],
        );
    }

    public function test_authorized_for_a_workspace_named_in_the_audience(): void
    {
        $provider = new GrandpaSSOnIdentityProvider();
        $subject = $this->serviceSubject(['workspace/7']);

        $this->assertTrue($provider->isAuthorizedForWorkspace($subject, 7));
    }

    public function test_not_authorized_for_a_workspace_not_named_in_the_audience(): void
    {
        $provider = new GrandpaSSOnIdentityProvider();
        $subject = $this->serviceSubject(['workspace/7']);

        $this->assertFalse($provider->isAuthorizedForWorkspace($subject, 8));
    }

    public function test_read_only_service_token_cannot_write_a_workspace(): void
    {
        $provider = new GrandpaSSOnIdentityProvider();
        $subject = $this->serviceSubjectWithScopes(['workspace/7'], ['kb:read']);

        $this->assertFalse($provider->canWriteWorkspace($subject, 7));
    }

    public function test_write_service_token_can_write_a_workspace_in_the_audience(): void
    {
        $provider = new GrandpaSSOnIdentityProvider();
        $subject = $this->serviceSubject(['workspace/7']);

        $this->assertTrue($provider->canWriteWorkspace($subject, 7));
    }

    public function test_write_service_token_cannot_write_a_workspace_outside_the_audience(): void
    {
        $provider = new GrandpaSSOnIdentityProvider();
        $subject = $this->serviceSubject(['workspace/7']);

        $this->assertFalse($provider->canWriteWorkspace($subject, 8));
    }

    public function test_accessible_workspace_ids_are_parsed_from_every_audience(): void
    {
        $provider = new GrandpaSSOnIdentityProvider();
        $subject = $this->serviceSubject(['workspace/7', 'workspace/9']);

        $this->assertSame([7, 9], $provider->accessibleWorkspaceIds($subject));
    }

    public function test_accessible_tenant_ids_resolve_via_the_accessible_workspaces(): void
    {
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);
        $workspace = Workspace::create([
            'tenant_id' => $tenant->id,
            'slug' => 'main',
            'name' => 'Main',
            'vault_path' => storage_path('app/vaults/svc_test'),
        ]);

        $provider = new GrandpaSSOnIdentityProvider();
        $subject = $this->serviceSubject(["workspace/{$workspace->id}"]);

        $this->assertSame([$tenant->id], $provider->accessibleTenantIds($subject));
    }
}
