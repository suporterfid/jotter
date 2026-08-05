<?php

namespace Tests\Feature\GrandpaSson;

use App\Domain\Auth\GrandpaSson\HttpIntrospectionClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class HttpIntrospectionClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'jotter.grandpasson_resource.introspect_url' => 'https://sso.example.test/oauth/introspect',
            'jotter.grandpasson_resource.client_id' => 'svc-jotter',
            'jotter.grandpasson_resource.client_secret' => 'secret123',
        ]);
    }

    public function test_returns_an_active_result_for_a_valid_token(): void
    {
        Http::fake([
            'sso.example.test/*' => Http::response([
                'active' => true,
                'scope' => 'kb:read kb:write',
                'aud' => 'workspace/7',
                'client_id' => 'svc-acme-integration',
            ], 200),
        ]);

        $client = new HttpIntrospectionClient();
        $result = $client->introspect('some-bearer-token');

        $this->assertTrue($result->active);
        $this->assertSame(['kb:read', 'kb:write'], $result->scopes);
        $this->assertSame(['workspace/7'], $result->audiences);
        $this->assertSame('svc-acme-integration', $result->clientId);
    }

    public function test_returns_inactive_when_grandpasson_says_the_token_is_inactive(): void
    {
        Http::fake([
            'sso.example.test/*' => Http::response(['active' => false], 200),
        ]);

        $result = (new HttpIntrospectionClient())->introspect('revoked-token');

        $this->assertFalse($result->active);
    }

    public function test_returns_inactive_on_a_non_2xx_response_rather_than_throwing(): void
    {
        Http::fake([
            'sso.example.test/*' => Http::response(['error' => 'server_error'], 500),
        ]);

        $result = (new HttpIntrospectionClient())->introspect('any-token');

        $this->assertFalse($result->active);
    }

    public function test_accepts_a_list_shaped_aud_claim(): void
    {
        Http::fake([
            'sso.example.test/*' => Http::response([
                'active' => true,
                'scope' => 'kb:read',
                'aud' => ['workspace/7'],
                'client_id' => 'svc-acme-integration',
            ], 200),
        ]);

        $result = (new HttpIntrospectionClient())->introspect('some-bearer-token');

        $this->assertSame(['workspace/7'], $result->audiences);
    }
}
