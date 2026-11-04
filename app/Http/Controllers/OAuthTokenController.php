<?php

namespace App\Http\Controllers;

use App\Domain\OAuth\OAuthError;
use App\Domain\OAuth\OAuthServer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Token endpoint (authorization_code, refresh_token) and RFC 7009 revocation.
 * Form-encoded; client authentication by client_secret_basic, client_secret_post,
 * or client_id alone for public clients.
 */
final class OAuthTokenController extends Controller
{
    public function __construct(private readonly OAuthServer $server) {}

    public function token(Request $request): JsonResponse
    {
        if (($limited = $this->limit($request)) !== null) {
            return $limited;
        }

        try {
            $client = $this->client($request);
            $payload = match ((string) $request->input('grant_type')) {
                'authorization_code' => $this->server->exchangeCode(
                    $client,
                    (string) $request->input('code', ''),
                    (string) $request->input('redirect_uri', ''),
                    (string) $request->input('code_verifier', ''),
                    $request->input('resource') !== null ? (string) $request->input('resource') : null,
                ),
                'refresh_token' => $this->server->refresh($client, (string) $request->input('refresh_token', '')),
                default => throw new OAuthError('unsupported_grant_type', 'Use authorization_code or refresh_token.'),
            };
        } catch (OAuthError $error) {
            $headers = ['Cache-Control' => 'no-store'];
            if ($error->status === 401 && $request->getUser() !== null) {
                $headers['WWW-Authenticate'] = 'Basic realm="jotter"';
            }

            return response()->json($error->toArray(), $error->status, $headers);
        }

        return response()->json($payload, 200, ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache'], JSON_UNESCAPED_SLASHES);
    }

    public function revoke(Request $request): JsonResponse
    {
        if (($limited = $this->limit($request)) !== null) {
            return $limited;
        }

        try {
            $client = $this->client($request);
        } catch (OAuthError $error) {
            return response()->json($error->toArray(), $error->status);
        }

        $token = (string) $request->input('token', '');
        if ($token !== '') {
            $this->server->revokeToken($client, $token);
        }

        return response()->json(new \stdClass, 200, ['Cache-Control' => 'no-store']);
    }

    private function client(Request $request): \App\Models\OAuthClient
    {
        if ($request->getUser() !== null) {
            return $this->server->authenticateClient(
                rawurldecode((string) $request->getUser()),
                rawurldecode((string) $request->getPassword()),
                'client_secret_basic',
            );
        }

        $secret = $request->input('client_secret');

        return $this->server->authenticateClient(
            $request->input('client_id') !== null ? (string) $request->input('client_id') : null,
            $secret !== null ? (string) $secret : null,
            $secret !== null ? 'client_secret_post' : 'none',
        );
    }

    private function limit(Request $request): ?JsonResponse
    {
        $key = 'oauth:token:'.$request->ip();
        $max = max(1, (int) config('jotter.oauth.token_requests_per_minute', 60));
        if (RateLimiter::tooManyAttempts($key, $max)) {
            return response()->json(['error' => 'slow_down', 'error_description' => 'Too many token requests.'], 429, [
                'Retry-After' => (string) RateLimiter::availableIn($key),
            ]);
        }
        RateLimiter::hit($key, 60);

        return null;
    }
}
