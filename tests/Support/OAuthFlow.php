<?php

namespace Tests\Support;

use App\Domain\OAuth\OAuthServer;
use Illuminate\Testing\TestResponse;

/**
 * Drives the embedded OAuth server like an MCP client would: DCR, authorize
 * with PKCE, consent, token. Used by the OAuth feature tests.
 */
trait OAuthFlow
{
    protected string $redirectUri = 'https://claude.ai/api/mcp/auth_callback';

    protected string $verifier = 'verifier-0123456789-abcdefghijklmnopqrstuvwxyz-ABCDEFG';

    /**
     * @return array<string, mixed>
     */
    protected function registerClient(?string $redirectUri = null, string $name = 'Claude'): array
    {
        return $this->postJson('/oauth/register', [
            'client_name' => $name,
            'redirect_uris' => [$redirectUri ?? $this->redirectUri],
            'token_endpoint_auth_method' => 'none',
            'grant_types' => ['authorization_code', 'refresh_token'],
        ])->assertCreated()->json();
    }

    /**
     * @param array<string, string> $overrides
     */
    protected function authorizeUrl(string $clientId, array $overrides = []): string
    {
        return '/oauth/authorize?'.http_build_query(array_merge([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $this->redirectUri,
            'state' => 'state-123',
            'code_challenge' => OAuthServer::pkceChallenge($this->verifier),
            'code_challenge_method' => 'S256',
            'scope' => 'mcp:read mcp:write offline_access',
            'resource' => OAuthServer::resource(),
        ], $overrides));
    }

    protected function consentRequestId(TestResponse $consent): string
    {
        preg_match('/name="request_id" value="([^"]+)"/', (string) $consent->getContent(), $matches);
        $this->assertNotEmpty($matches[1] ?? null, 'consent form has a request_id');

        return $matches[1];
    }

    /**
     * Approves the consent and returns the query string parameters sent back to the client.
     *
     * @param list<int> $workspaceIds
     * @return array<string, string>
     */
    protected function approve(string $requestId, array $workspaceIds, bool $write = false, string $writePaths = "inbox/{client}/**:create,append,update\ndiario/**:append"): array
    {
        $response = $this->post('/oauth/authorize', [
            'request_id' => $requestId,
            'decision' => 'approve',
            'workspace_ids' => $workspaceIds,
            'write' => $write ? '1' : null,
            'write_paths' => $writePaths,
            'client_slug' => 'claude',
            'expires' => '90d',
        ]);
        $response->assertRedirect();
        $location = (string) $response->headers->get('Location');
        $this->assertStringStartsWith($this->redirectUri.'?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        return $query;
    }

    protected function exchange(string $clientId, string $code, ?string $verifier = null): TestResponse
    {
        return $this->post('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'code' => $code,
            'redirect_uri' => $this->redirectUri,
            'code_verifier' => $verifier ?? $this->verifier,
            'resource' => OAuthServer::resource(),
        ]);
    }

    protected function mcpPing(string $accessToken): TestResponse
    {
        return $this->mcp($accessToken, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']);
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function mcp(string $accessToken, array $payload): TestResponse
    {
        $response = $this->withToken($accessToken)->postJson('/api/mcp', $payload);
        // withToken() sticks to later requests; the consent screen refuses bearer callers.
        $this->flushHeaders();

        return $response;
    }
}
