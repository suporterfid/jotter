<?php

namespace App\Domain\Auth\GrandpaSson;

use Illuminate\Support\Facades\Http;

/**
 * Calls GrandpaSSOn's existing /oauth/introspect endpoint (RFC 7662-style).
 * Ported from TaskConnect's identical integration
 * (taskconnect/app/Application/GrandpaSson/HttpIntrospectionClient.php) — same
 * shared identity broker, same introspection contract.
 */
final class HttpIntrospectionClient implements IntrospectionClientInterface
{
    public function introspect(string $token): IntrospectionResult
    {
        $url = (string) config('jotter.grandpasson_resource.introspect_url');
        $clientId = (string) config('jotter.grandpasson_resource.client_id');
        $clientSecret = (string) config('jotter.grandpasson_resource.client_secret');

        if ($url === '') {
            return new IntrospectionResult(active: false);
        }

        $request = Http::asForm()->timeout(10);
        if ($clientId !== '' && $clientSecret !== '') {
            $request = $request->withBasicAuth($clientId, $clientSecret);
        }

        $response = $request->post($url, ['token' => $token]);

        if (! $response->successful()) {
            return new IntrospectionResult(active: false);
        }

        $active = (bool) $response->json('active', false);
        $scopeRaw = $response->json('scope', '');
        $scopes = is_string($scopeRaw)
            ? array_values(array_filter(explode(' ', $scopeRaw)))
            : (is_array($scopeRaw) ? array_map('strval', $scopeRaw) : []);

        $audRaw = $response->json('aud', []);
        $audiences = match (true) {
            is_string($audRaw) && $audRaw !== '' => [$audRaw],
            is_array($audRaw) => array_map('strval', $audRaw),
            default => [],
        };

        return new IntrospectionResult(
            active: $active,
            scopes: $scopes,
            audiences: $audiences,
            clientId: $response->json('client_id'),
            subject: $response->json('sub'),
        );
    }
}
