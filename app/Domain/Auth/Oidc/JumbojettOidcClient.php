<?php

namespace App\Domain\Auth\Oidc;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Session\Store;
use Jumbojett\OpenIDConnectClient;
use Jumbojett\OpenIDConnectClientException;
use Throwable;

final class OidcProtocolException extends \RuntimeException
{
}

final class LaravelOpenIDConnectClient extends OpenIDConnectClient
{
    public function __construct(
        string $providerUrl,
        string $clientId,
        string $clientSecret,
        private readonly Store $session,
    ) {
        parent::__construct($providerUrl, $clientId, $clientSecret);
    }

    protected function startSession(): void
    {
    }

    protected function commitSession(): void
    {
    }

    protected function getSessionKey(string $key)
    {
        return $this->session->get($key, false);
    }

    protected function setSessionKey(string $key, $value): void
    {
        $this->session->put($key, $value);
    }

    protected function unsetSessionKey(string $key): void
    {
        $this->session->forget($key);
    }
}

final class JumbojettOidcClient implements OidcClientInterface
{
    public function __construct(
        private readonly ?OpenIDConnectClient $client = null,
        private readonly ?array $configuration = null,
    ) {
    }

    public function authorizationUrl(Request $request): string
    {
        $configuration = $this->configuration();
        $metadata = $this->discover($configuration);

        if (! in_array('S256', $metadata['code_challenge_methods_supported'] ?? [], true)) {
            throw new OidcProtocolException('OIDC provider does not support S256 PKCE.');
        }

        $state = $this->randomToken();
        $nonce = $this->randomToken();
        $verifier = rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $request->session()->put([
            'oidc.challenge' => [
                'state' => $state,
                'nonce' => $nonce,
                'expires_at' => now()->addMinutes(10)->timestamp,
                'consumed_at' => null,
            ],
            'openid_connect_state' => $state,
            'openid_connect_nonce' => $nonce,
            'openid_connect_code_verifier' => $verifier,
        ]);

        $scopes = array_values(array_unique(array_merge(['openid'], $configuration['scopes'] ?? [])));

        return $metadata['authorization_endpoint'].'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $configuration['client_id'],
            'redirect_uri' => $configuration['redirect_uri'],
            'scope' => implode(' ', $scopes),
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function authenticateCallback(Request $request): OidcClaims
    {
        $configuration = $this->configuration();

        if ($request->query('error') !== null) {
            throw new OidcProtocolException('OIDC provider returned an error.');
        }

        $code = (string) $request->query('code', '');
        $state = (string) $request->query('state', '');
        $challenge = $request->session()->get('oidc.challenge');

        if ($code === '' || $state === '' || ! is_array($challenge)) {
            throw new OidcProtocolException('OIDC callback challenge is incomplete.');
        }

        if (($challenge['consumed_at'] ?? null) !== null) {
            throw new OidcProtocolException('OIDC callback challenge was already consumed.');
        }

        if (($challenge['expires_at'] ?? 0) < now()->timestamp) {
            throw new OidcProtocolException('OIDC callback challenge expired.');
        }

        if (! is_string($challenge['state'] ?? null) || ! hash_equals($challenge['state'], $state)) {
            throw new OidcProtocolException('OIDC callback state mismatch.');
        }

        $request->session()->put('oidc.challenge.consumed_at', now()->timestamp);
        $previousRequest = $_REQUEST;
        $_REQUEST = array_merge($_REQUEST, $request->query->all());

        try {
            $client = $this->client ?? new LaravelOpenIDConnectClient(
                $configuration['issuer_url'],
                $configuration['client_id'],
                $configuration['client_secret'],
                $request->session(),
            );
            $this->configureClient($client, $configuration);

            if (! $client->authenticate()) {
                throw new OidcProtocolException('OIDC client did not authenticate.');
            }

            return $this->normalizeClaims($client->getVerifiedClaims(), $configuration);
        } catch (OidcProtocolException $exception) {
            throw $exception;
        } catch (OpenIDConnectClientException|Throwable $exception) {
            throw new OidcProtocolException('OIDC callback validation failed.', 0, $exception);
        } finally {
            $_REQUEST = $previousRequest;
            $request->session()->forget([
                'oidc.challenge',
                'openid_connect_state',
                'openid_connect_nonce',
                'openid_connect_code_verifier',
            ]);
        }
    }

    private function configuration(): array
    {
        $configuration = $this->configuration ?? config('jotter.oidc', []);

        if (! is_array($configuration) || ! ($configuration['configured'] ?? false)) {
            throw new OidcProtocolException('OIDC provider is not configured.');
        }

        return $configuration;
    }

    private function discover(array $configuration): array
    {
        $issuer = rtrim((string) $configuration['issuer_url'], '/');

        if (! ($configuration['allow_insecure_http'] ?? false) && ! str_starts_with($issuer, 'https://')) {
            throw new OidcProtocolException('OIDC issuer must use HTTPS.');
        }

        $response = Http::acceptJson()->timeout(10)->get($issuer.'/.well-known/openid-configuration');

        if (! $response->successful()) {
            throw new OidcProtocolException('OIDC discovery failed.');
        }

        $metadata = $response->json();

        if (! is_array($metadata)
            || ! filled($metadata['authorization_endpoint'] ?? null)
            || ! filled($metadata['token_endpoint'] ?? null)
            || ! filled($metadata['jwks_uri'] ?? null)
        ) {
            throw new OidcProtocolException('OIDC discovery metadata is incomplete.');
        }

        return $metadata;
    }

    private function configureClient(OpenIDConnectClient $client, array $configuration): void
    {
        $client->setRedirectURL($configuration['redirect_uri']);
        $client->addScope(array_values(array_unique(array_merge(['openid'], $configuration['scopes'] ?? []))));
        $client->setResponseTypes(['code']);
        $client->setCodeChallengeMethod('S256');
    }

    private function normalizeClaims(object|array $rawClaims, array $configuration): OidcClaims
    {
        $claims = is_object($rawClaims) ? get_object_vars($rawClaims) : $rawClaims;
        $issuer = trim((string) ($claims['iss'] ?? ''));
        $subject = trim((string) ($claims['sub'] ?? ''));
        $email = strtolower(trim((string) ($claims['email'] ?? '')));

        if ($issuer === '' || $subject === '' || $email === '') {
            throw new OidcProtocolException('OIDC claims are incomplete.');
        }

        $hasEmailVerified = array_key_exists('email_verified', $claims);
        $emailVerified = $claims['email_verified'] ?? false;

        if ((! $hasEmailVerified && ! ($configuration['trusted_email_claim'] ?? false))
            || ($hasEmailVerified && $emailVerified !== true)
        ) {
            throw new OidcProtocolException('OIDC email is not verified.');
        }

        $locale = strtolower(str_replace('_', '-', trim((string) ($claims['locale'] ?? ''))));

        return new OidcClaims(
            issuer: $issuer,
            subject: $subject,
            email: $email,
            emailVerified: true,
            name: trim((string) ($claims['name'] ?? $email)) ?: $email,
            locale: str_starts_with($locale, 'pt') ? 'pt-BR' : 'en',
        );
    }

    private function randomToken(): string
    {
        return bin2hex(random_bytes(32));
    }
}
