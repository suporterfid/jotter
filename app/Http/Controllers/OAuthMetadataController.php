<?php

namespace App\Http\Controllers;

use App\Domain\OAuth\ClientMetadataDocuments;
use App\Domain\OAuth\OAuthServer;
use Illuminate\Http\JsonResponse;

/**
 * RFC 9728 protected-resource metadata and RFC 8414 authorization-server
 * metadata. MCP clients find these from the 401 `WWW-Authenticate` header.
 */
final class OAuthMetadataController extends Controller
{
    public function protectedResource(): JsonResponse
    {
        return $this->json([
            'resource' => OAuthServer::resource(),
            'authorization_servers' => [OAuthServer::issuer()],
            'scopes_supported' => ['mcp:read', 'mcp:write'],
            'bearer_methods_supported' => ['header'],
            'resource_name' => (string) config('app.name', 'Jotter').' MCP',
        ]);
    }

    public function authorizationServer(): JsonResponse
    {
        $issuer = OAuthServer::issuer();

        return $this->json([
            'issuer' => $issuer,
            'authorization_endpoint' => $issuer.'/oauth/authorize',
            'token_endpoint' => $issuer.'/oauth/token',
            'registration_endpoint' => $issuer.'/oauth/register',
            'revocation_endpoint' => $issuer.'/oauth/revoke',
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => OAuthServer::AUTH_METHODS,
            'revocation_endpoint_auth_methods_supported' => OAuthServer::AUTH_METHODS,
            'scopes_supported' => OAuthServer::SUPPORTED_SCOPES,
            'authorization_response_iss_parameter_supported' => true,
            'client_id_metadata_document_supported' => ClientMetadataDocuments::enabled(),
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(array $payload): JsonResponse
    {
        return response()->json($payload, 200, ['Cache-Control' => 'public, max-age=300'], JSON_UNESCAPED_SLASHES);
    }
}
