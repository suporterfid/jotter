<?php

namespace App\Http\Controllers;

use App\Domain\OAuth\OAuthError;
use App\Domain\OAuth\OAuthServer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * RFC 7591 dynamic client registration, limited per IP.
 */
final class OAuthRegistrationController extends Controller
{
    public function __construct(private readonly OAuthServer $server) {}

    public function store(Request $request): JsonResponse
    {
        $key = 'oauth:register:'.$request->ip();
        $max = max(1, (int) config('jotter.oauth.registrations_per_hour', 10));
        if (RateLimiter::tooManyAttempts($key, $max)) {
            return response()->json(['error' => 'slow_down', 'error_description' => 'Too many registrations from this address.'], 429, [
                'Retry-After' => (string) RateLimiter::availableIn($key),
            ]);
        }
        RateLimiter::hit($key, 3600);

        try {
            ['client' => $client, 'secret' => $secret] = $this->server->register((array) $request->json()->all(), $request->ip());
        } catch (OAuthError $error) {
            return response()->json($error->toArray(), $error->status);
        }

        return response()->json(array_filter([
            'client_id' => $client->client_id,
            'client_id_issued_at' => $client->created_at?->getTimestamp(),
            'client_secret' => $secret,
            'client_secret_expires_at' => $secret === null ? null : 0,
            'client_name' => $client->name,
            'redirect_uris' => $client->redirect_uris,
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => $client->auth_method,
        ], static fn ($value): bool => $value !== null), 201, ['Cache-Control' => 'no-store'], JSON_UNESCAPED_SLASHES);
    }
}
