<?php

namespace Tests\Feature;

use App\Domain\OAuth\RedirectUriPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class OAuthMetadataTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_oauth_route_is_404_while_disabled(): void
    {
        config(['jotter.oauth.enabled' => false]);

        $this->getJson('/.well-known/oauth-protected-resource/api/mcp')->assertNotFound();
        $this->getJson('/.well-known/oauth-authorization-server')->assertNotFound();
        $this->postJson('/oauth/register', ['redirect_uris' => ['https://claude.ai/api/mcp/auth_callback']])->assertNotFound();
        $this->get('/oauth/authorize')->assertNotFound();
        $this->post('/oauth/token')->assertNotFound();
        $this->post('/oauth/revoke')->assertNotFound();
    }

    public function test_metadata_documents_follow_rfc_9728_and_8414(): void
    {
        config(['jotter.oauth.enabled' => true, 'app.url' => 'https://brain.example.test/']);

        foreach (['/.well-known/oauth-protected-resource', '/.well-known/oauth-protected-resource/api/mcp'] as $path) {
            $this->getJson($path)->assertOk()
                ->assertJsonPath('resource', 'https://brain.example.test/api/mcp')
                ->assertJsonPath('authorization_servers', ['https://brain.example.test'])
                ->assertJsonPath('bearer_methods_supported', ['header']);
        }

        $this->getJson('/.well-known/oauth-authorization-server')->assertOk()
            ->assertJsonPath('issuer', 'https://brain.example.test')
            ->assertJsonPath('authorization_endpoint', 'https://brain.example.test/oauth/authorize')
            ->assertJsonPath('token_endpoint', 'https://brain.example.test/oauth/token')
            ->assertJsonPath('registration_endpoint', 'https://brain.example.test/oauth/register')
            ->assertJsonPath('revocation_endpoint', 'https://brain.example.test/oauth/revoke')
            ->assertJsonPath('code_challenge_methods_supported', ['S256'])
            ->assertJsonPath('grant_types_supported', ['authorization_code', 'refresh_token'])
            ->assertJsonPath('token_endpoint_auth_methods_supported', ['none', 'client_secret_post', 'client_secret_basic'])
            ->assertJsonPath('scopes_supported', ['mcp:read', 'mcp:write', 'offline_access']);

        // The MCP 401 now points at the metadata.
        $this->assertStringContainsString(
            'resource_metadata="https://brain.example.test/.well-known/oauth-protected-resource/api/mcp"',
            (string) $this->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])->assertStatus(401)->headers->get('WWW-Authenticate'),
        );
    }

    public function test_dynamic_registration_enforces_the_redirect_allowlist_and_rate_limit(): void
    {
        config(['jotter.oauth.enabled' => true, 'jotter.oauth.registrations_per_hour' => 3]);

        $this->postJson('/oauth/register', ['client_name' => 'Evil', 'redirect_uris' => ['https://evil.example/cb']])
            ->assertStatus(400)->assertJsonPath('error', 'invalid_redirect_uri');
        $this->postJson('/oauth/register', ['client_name' => 'Claude Code', 'redirect_uris' => ['http://127.0.0.1:53682/callback']])
            ->assertCreated()->assertJsonPath('token_endpoint_auth_method', 'none');
        $confidential = $this->postJson('/oauth/register', ['client_name' => 'Server app', 'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'], 'token_endpoint_auth_method' => 'client_secret_post'])
            ->assertCreated();
        $this->assertStringStartsWith('jt_ocs_', $confidential->json('client_secret'));

        $this->postJson('/oauth/register', ['redirect_uris' => ['https://claude.ai/api/mcp/auth_callback']])->assertStatus(429);
        $this->assertDatabaseHas('audit_log', ['event' => 'oauth.client_registered']);
    }

    public function test_redirect_policy_rules(): void
    {
        $policy = new RedirectUriPolicy(['https://claude.ai/api/mcp/auth_callback', 'http://localhost:*/*', 'https://chatgpt.com/connector/*']);

        $this->assertTrue($policy->allowedForRegistration('https://claude.ai/api/mcp/auth_callback'));
        $this->assertFalse($policy->allowedForRegistration('https://claude.ai/api/mcp/auth_callback/x'));
        $this->assertTrue($policy->allowedForRegistration('http://localhost:33418/cb'));
        $this->assertTrue($policy->allowedForRegistration('https://chatgpt.com/connector/oauth/abc'));
        $this->assertFalse($policy->allowedForRegistration('http://claude.ai/api/mcp/auth_callback'), 'plain http only for loopback');
        $this->assertFalse($policy->allowedForRegistration('https://claude.ai/api/mcp/auth_callback#frag'));
        $this->assertFalse($policy->allowedForRegistration('https://user:pw@claude.ai/api/mcp/auth_callback'));
        $this->assertFalse($policy->allowedForRegistration('http://127.0.0.1:8080/cb'), 'not in this allowlist');

        $this->assertTrue($policy->matchesRegistered('http://localhost:61000/cb', ['http://localhost:33418/cb']), 'loopback port is free');
        $this->assertFalse($policy->matchesRegistered('http://localhost:61000/other', ['http://localhost:33418/cb']));
        $this->assertFalse($policy->matchesRegistered('https://claude.ai/api/mcp/auth_callback?x=1', ['https://claude.ai/api/mcp/auth_callback']));
    }
}
