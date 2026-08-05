<?php

namespace Tests\Unit\GrandpaSson;

use App\Domain\Auth\GrandpaSson\IntrospectionResult;
use App\Domain\Auth\Providers\GrandpaSSOnIdentityProvider;
use Illuminate\Http\Request;
use Tests\Support\FakeIntrospectionClient;
use Tests\TestCase;

final class GrandpaSSOnServiceTokenIdentityTest extends TestCase
{
    public function test_resolves_a_synthetic_subject_for_an_active_service_token(): void
    {
        config(['jotter.grandpasson_resource.inbound_enabled' => true]);

        $introspection = new FakeIntrospectionClient(new IntrospectionResult(
            active: true,
            scopes: ['kb:read', 'kb:write'],
            audiences: ['workspace/7'],
            clientId: 'svc-acme-integration',
        ));
        $provider = new GrandpaSSOnIdentityProvider($introspection);

        $request = Request::create('/api/workspaces/7/notes', 'GET');
        $request->headers->set('Authorization', 'Bearer some-token');

        $subject = $provider->resolveIdentity($request);

        $this->assertNotNull($subject);
        $this->assertSame('service:svc-acme-integration', $subject->subjectId);
        $this->assertFalse($subject->isAdmin);
        $this->assertNull($subject->user);
        $this->assertSame('grandpasson_service_token', $subject->attributes['auth_method']);
        $this->assertSame(['kb:read', 'kb:write'], $subject->attributes['scopes']);
        $this->assertSame(['workspace/7'], $subject->attributes['audiences']);
    }

    public function test_returns_null_for_an_inactive_token(): void
    {
        config(['jotter.grandpasson_resource.inbound_enabled' => true]);

        $introspection = new FakeIntrospectionClient(new IntrospectionResult(active: false));
        $provider = new GrandpaSSOnIdentityProvider($introspection);

        $request = Request::create('/api/workspaces/7/notes', 'GET');
        $request->headers->set('Authorization', 'Bearer revoked-token');

        $this->assertNull($provider->resolveIdentity($request));
    }

    public function test_bearer_token_is_ignored_entirely_when_inbound_is_disabled(): void
    {
        config(['jotter.grandpasson_resource.inbound_enabled' => false]);

        $introspection = new FakeIntrospectionClient(new IntrospectionResult(
            active: true,
            scopes: ['kb:read'],
            audiences: ['workspace/7'],
            clientId: 'svc-acme-integration',
        ));
        $provider = new GrandpaSSOnIdentityProvider($introspection);

        $request = Request::create('/api/workspaces/7/notes', 'GET');
        $request->headers->set('Authorization', 'Bearer some-token');

        // No cookie, no session either — falls through to null, same as
        // if this feature didn't exist at all.
        $this->assertNull($provider->resolveIdentity($request));
    }
}
