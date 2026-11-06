<?php

namespace App\Domain\OAuth;

use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditRecorder;
use App\Models\OAuthClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Client ID Metadata Documents (#411; draft-ietf-oauth-client-id-metadata-document,
 * recommended by the MCP 2025-11-25 authorization spec). A client_id that is an
 * https URL is fetched, validated and cached in `oauth_clients` (kind=cimd), so
 * the client needs no prior registration.
 *
 * The fetch is the server reaching out on behalf of an unauthenticated caller,
 * so it is guarded against SSRF: https on port 443 only, no IP-literal hosts,
 * every resolved address public, the connection pinned to a checked address,
 * no redirects, a short timeout and a small size cap. Documents are public
 * clients only (`token_endpoint_auth_method: none`) and their redirect URIs
 * must pass the same allowlist as dynamic registration.
 */
final class ClientMetadataDocuments
{
    public function __construct(
        private readonly HostResolver $resolver,
        private readonly RedirectUriPolicy $redirects = new RedirectUriPolicy,
        private readonly AuditRecorder $audit = new AuditRecorder,
    ) {}

    public static function enabled(): bool
    {
        return (bool) config('jotter.oauth.cimd.enabled', true);
    }

    /**
     * Whether $clientId has the shape of a metadata document URL: https, a host,
     * a path other than "/", no credentials, fragment, dot segments or port
     * other than 443.
     */
    public static function isMetadataUrl(string $clientId): bool
    {
        if (strlen($clientId) > 512 || ! str_starts_with($clientId, 'https://')) {
            return false;
        }
        $parts = parse_url($clientId);
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') === '') {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            return false;
        }
        if (isset($parts['port']) && (int) $parts['port'] !== 443) {
            return false;
        }
        $path = (string) ($parts['path'] ?? '');
        if ($path === '' || $path === '/') {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    /**
     * The cached client for $url, fetched again when missing or expired.
     *
     * @throws OAuthError invalid_client with a reason the consent error page shows
     */
    public function resolve(string $url): OAuthClient
    {
        if (! self::enabled() || ! self::isMetadataUrl($url)) {
            throw new OAuthError('invalid_client', 'client_id is not a usable metadata document URL.');
        }

        /** @var OAuthClient|null $cached */
        $cached = OAuthClient::query()->where('client_id', $url)->first();
        if ($cached !== null && $cached->kind === OAuthClient::KIND_CIMD
            && $cached->metadata_expires_at !== null && $cached->metadata_expires_at->isFuture()) {
            return $cached;
        }

        [$metadata, $ttl] = $this->fetch($url);
        $name = trim((string) ($metadata['client_name'] ?? ''));
        $host = (string) parse_url($url, PHP_URL_HOST);

        $client = OAuthClient::query()->updateOrCreate(['client_id' => $url], [
            'kind' => OAuthClient::KIND_CIMD,
            'name' => $name === '' ? $host : mb_substr($name, 0, 120),
            'redirect_uris' => $metadata['redirect_uris'],
            'auth_method' => 'none',
            'secret_hash' => null,
            'metadata_expires_at' => now()->addSeconds($ttl),
        ]);

        if ($cached === null) {
            $this->audit->record(event: AuditEvent::OAUTH_CLIENT_REGISTERED, metadata: [
                'client_id' => $url, 'name' => $client->name, 'redirect_uris' => $client->redirect_uris, 'source' => 'cimd',
            ]);
        }

        return $client;
    }

    /**
     * @return array{0: array{client_name?: mixed, redirect_uris: list<string>}, 1: int}
     */
    private function fetch(string $url): array
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $address = $this->publicAddress($host);
        $maxBytes = max(512, (int) config('jotter.oauth.cimd.max_bytes', 5120));

        $options = [
            'allow_redirects' => false,
            'progress' => static function ($downloadTotal, $downloaded) use ($maxBytes): void {
                if ($downloadTotal > $maxBytes || $downloaded > $maxBytes) {
                    throw new \RuntimeException('Client metadata document is too large.');
                }
            },
        ];
        if (defined('CURLOPT_RESOLVE')) {
            // Pin the connection to the address we checked (no DNS rebinding).
            $options['curl'] = [CURLOPT_RESOLVE => [$host.':443:'.(str_contains($address, ':') ? '['.$address.']' : $address)]];
        }

        try {
            /** @var Response $response */
            $response = Http::withOptions($options)
                ->timeout(max(1, (int) config('jotter.oauth.cimd.timeout_seconds', 5)))
                ->connectTimeout(3)
                ->accept('application/json')
                ->withUserAgent('Jotter-OAuth-CIMD')
                ->get($url);
        } catch (ConnectionException) {
            throw new OAuthError('invalid_client', 'The client metadata document could not be fetched.');
        } catch (Throwable $exception) {
            throw new OAuthError('invalid_client', str_contains($exception->getMessage(), 'too large')
                ? 'The client metadata document is too large.'
                : 'The client metadata document could not be fetched.');
        }

        if ($response->status() !== 200) {
            throw new OAuthError('invalid_client', sprintf('The client metadata document answered HTTP %d.', $response->status()));
        }
        $body = $response->body();
        if (strlen($body) > $maxBytes) {
            throw new OAuthError('invalid_client', 'The client metadata document is too large.');
        }

        $metadata = json_decode($body, true);
        if (! is_array($metadata) || array_is_list($metadata)) {
            throw new OAuthError('invalid_client', 'The client metadata document is not a JSON object.');
        }
        if (($metadata['client_id'] ?? null) !== $url) {
            throw new OAuthError('invalid_client', 'The client metadata document names a different client_id.');
        }

        $redirectUris = $metadata['redirect_uris'] ?? null;
        if (! is_array($redirectUris) || $redirectUris === [] || count($redirectUris) > 10 || ! array_is_list($redirectUris)) {
            throw new OAuthError('invalid_client', 'The client metadata document must list 1 to 10 redirect_uris.');
        }
        foreach ($redirectUris as $uri) {
            if (! is_string($uri) || ! $this->redirects->allowedForRegistration($uri)) {
                throw new OAuthError('invalid_client', 'Redirect URI not allowed on this server: '.(is_string($uri) ? $uri : '(invalid)').'. Ask the operator to add it to JOTTER_OAUTH_REDIRECT_ALLOWLIST.');
            }
        }

        $method = (string) ($metadata['token_endpoint_auth_method'] ?? 'none');
        if ($method !== 'none') {
            throw new OAuthError('invalid_client', 'Only public clients (token_endpoint_auth_method "none") are supported with metadata documents.');
        }
        foreach ((array) ($metadata['grant_types'] ?? ['authorization_code']) as $grantType) {
            if (! in_array($grantType, ['authorization_code', 'refresh_token'], true)) {
                throw new OAuthError('invalid_client', 'Only authorization_code and refresh_token grants are supported.');
            }
        }

        return [
            ['client_name' => $metadata['client_name'] ?? null, 'redirect_uris' => array_values($redirectUris)],
            $this->ttl((string) $response->header('Cache-Control')),
        ];
    }

    private function publicAddress(string $host): string
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false || str_starts_with($host, '[')) {
            throw new OAuthError('invalid_client', 'The client metadata document must be served from a host name, not an IP address.');
        }

        $addresses = $this->resolver->resolve($host);
        if ($addresses === []) {
            throw new OAuthError('invalid_client', 'The client metadata document host does not resolve.');
        }
        foreach ($addresses as $address) {
            if (! self::isPublicAddress($address)) {
                throw new OAuthError('invalid_client', 'The client metadata document host resolves to a private or reserved address.');
            }
        }

        return $addresses[0];
    }

    public static function isPublicAddress(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        $packed = @inet_pton($address);
        if ($packed === false) {
            return false;
        }
        if (strlen($packed) === 16) {
            // IPv4-mapped / -compatible IPv6 (::ffff:a.b.c.d, ::a.b.c.d): judge the IPv4 part.
            if (str_starts_with($packed, str_repeat("\0", 10)."\xff\xff") || str_starts_with($packed, str_repeat("\0", 12))) {
                return self::isPublicAddress((string) inet_ntop(substr($packed, 12)));
            }
            $first = ord($packed[0]);

            // Unique local fc00::/7 and link-local fe80::/10.
            return ($first & 0xFE) !== 0xFC && ! ($first === 0xFE && (ord($packed[1]) & 0xC0) === 0x80);
        }

        // Shared address space (carrier-grade NAT) 100.64.0.0/10 and 0.0.0.0/8.
        $a = ord($packed[0]);
        $b = ord($packed[1]);

        return $a !== 0 && ! ($a === 100 && ($b & 0xC0) === 64);
    }

    private function ttl(string $cacheControl): int
    {
        $default = (int) config('jotter.oauth.cimd.default_ttl_seconds', 3600);
        $min = (int) config('jotter.oauth.cimd.min_ttl_seconds', 300);
        $max = (int) config('jotter.oauth.cimd.max_ttl_seconds', 86400);

        $ttl = preg_match('/(?:^|,)\s*max-age\s*=\s*(\d+)/i', $cacheControl, $matches) === 1 ? (int) $matches[1] : $default;
        if (preg_match('/(?:^|,)\s*(no-store|no-cache)\b/i', $cacheControl) === 1) {
            $ttl = $min;
        }

        return max($min, min($max, $ttl));
    }
}
