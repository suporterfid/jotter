<?php

namespace Tests\Unit\Oidc;

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Jumbojett\OpenIDConnectClient;
use Illuminate\Http\Request;
use App\Domain\Auth\Oidc\JumbojettOidcClient;
use App\Domain\Auth\Oidc\OidcProtocolException;
use Tests\TestCase;

class JumbojettOidcClientTest extends TestCase
{
    public function test_authorization_url_uses_discovery_code_and_pkce_s256(): void
    {
        config([
            'jotter.oidc' => [
                'issuer_url' => 'https://issuer.example.test',
                'client_id' => 'jotter-client',
                'client_secret' => 'secret',
                'redirect_uri' => 'https://jotter.example.test/api/auth/oidc/callback',
                'scopes' => ['openid', 'profile', 'email'],
                'post_login_redirect_uri' => 'https://jotter.example.test',
                'allow_insecure_http' => false,
                'trusted_email_claim' => false,
                'configured' => true,
            ],
        ]);

        Http::fake([
            'https://issuer.example.test/.well-known/openid-configuration' => Http::response([
                'issuer' => 'https://issuer.example.test',
                'authorization_endpoint' => 'https://issuer.example.test/authorize',
                'token_endpoint' => 'https://issuer.example.test/token',
                'jwks_uri' => 'https://issuer.example.test/jwks',
                'code_challenge_methods_supported' => ['S256'],
            ]),
        ]);

        $request = $this->sessionRequest('/api/auth/oidc/redirect');
        $url = (new JumbojettOidcClient(configuration: config('jotter.oidc')))->authorizationUrl($request);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame('https://issuer.example.test/authorize', strtok($url, '?'));
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('jotter-client', $query['client_id']);
        $this->assertSame('https://jotter.example.test/api/auth/oidc/callback', $query['redirect_uri']);
        $this->assertSame('openid profile email', $query['scope']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertNotEmpty($query['state']);
        $this->assertNotEmpty($query['nonce']);
        $this->assertSame($query['state'], $request->session()->get('openid_connect_state'));
        $this->assertSame($query['nonce'], $request->session()->get('openid_connect_nonce'));

        $verifier = $request->session()->get('openid_connect_code_verifier');
        $expectedChallenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $this->assertSame($expectedChallenge, $query['code_challenge']);
        Http::assertSent(fn (HttpRequest $sent): bool => $sent->url() === 'https://issuer.example.test/.well-known/openid-configuration');
    }

    public function test_callback_rejects_provider_errors_without_authenticating(): void
    {
        $request = $this->sessionRequest('/api/auth/oidc/callback?error=access_denied');
        $request->query->set('error', 'access_denied');

        $this->expectException(OidcProtocolException::class);
        (new JumbojettOidcClient(configuration: $this->configuredOidc()))->authenticateCallback($request);
    }

    public function test_callback_returns_only_validated_claims(): void
    {
        $request = $this->sessionRequest('/api/auth/oidc/callback?code=one-time-code&state=state');
        $request->query->set('code', 'one-time-code');
        $request->query->set('state', 'state');
        $request->session()->put([
            'oidc.challenge' => [
                'state' => 'state',
                'nonce' => 'nonce',
                'expires_at' => now()->addMinute()->timestamp,
                'consumed_at' => null,
            ],
            'openid_connect_state' => 'state',
            'openid_connect_nonce' => 'nonce',
            'openid_connect_code_verifier' => 'verifier',
        ]);

        $client = new FakeValidatedOidcClient('https://issuer.example.test', 'client', 'secret');
        $claims = (new JumbojettOidcClient($client, $this->configuredOidc()))->authenticateCallback($request);

        $this->assertSame('https://issuer.example.test', $claims->issuer);
        $this->assertSame('subject-123', $claims->subject);
        $this->assertSame('person@example.test', $claims->email);
        $this->assertTrue($claims->emailVerified);
        $this->assertSame('Person Example', $claims->name);
        $this->assertSame('en', $claims->locale);
        $this->assertNull($request->session()->get('oidc.challenge'));
    }

    public function test_callback_rejects_expired_challenges(): void
    {
        $request = $this->sessionRequest('/api/auth/oidc/callback?code=code&state=state');
        $request->query->set('code', 'code');
        $request->query->set('state', 'state');
        $request->session()->put('oidc.challenge', [
            'state' => 'state',
            'expires_at' => now()->subSecond()->timestamp,
            'consumed_at' => null,
        ]);

        $this->expectException(OidcProtocolException::class);
        (new JumbojettOidcClient(configuration: $this->configuredOidc()))->authenticateCallback($request);
    }

    public function test_callback_rejects_replayed_challenges(): void
    {
        $request = $this->sessionRequest('/api/auth/oidc/callback?code=code&state=state');
        $request->query->set('code', 'code');
        $request->query->set('state', 'state');
        $request->session()->put('oidc.challenge', [
            'state' => 'state',
            'expires_at' => now()->addMinute()->timestamp,
            'consumed_at' => now()->subSecond()->timestamp,
        ]);

        $this->expectException(OidcProtocolException::class);
        (new JumbojettOidcClient(configuration: $this->configuredOidc()))->authenticateCallback($request);
    }

    public function test_callback_rejects_an_unverified_email_claim(): void
    {
        $request = $this->sessionRequest('/api/auth/oidc/callback?code=code&state=state');
        $request->query->set('code', 'code');
        $request->query->set('state', 'state');
        $request->session()->put('oidc.challenge', [
            'state' => 'state',
            'expires_at' => now()->addMinute()->timestamp,
            'consumed_at' => null,
        ]);

        $this->expectException(OidcProtocolException::class);
        (new JumbojettOidcClient(
            new FakeUnverifiedOidcClient('https://issuer.example.test', 'client', 'secret'),
            $this->configuredOidc(),
        ))->authenticateCallback($request);
    }

    private function configuredOidc(): array
    {
        return [
            'issuer_url' => 'https://issuer.example.test',
            'client_id' => 'client',
            'client_secret' => 'secret',
            'redirect_uri' => 'https://jotter.example.test/api/auth/oidc/callback',
            'scopes' => ['openid', 'profile', 'email'],
            'post_login_redirect_uri' => 'https://jotter.example.test',
            'allow_insecure_http' => false,
            'trusted_email_claim' => false,
            'configured' => true,
        ];
    }

    private function sessionRequest(string $uri): Request
    {
        $request = Request::create($uri);
        $request->setLaravelSession(app('session.store'));

        return $request;
    }
}

final class FakeValidatedOidcClient extends OpenIDConnectClient
{
    public function authenticate(): bool
    {
        return true;
    }

    public function getVerifiedClaims(string $attribute = null): object
    {
        return (object) [
            'iss' => 'https://issuer.example.test',
            'sub' => 'subject-123',
            'email' => 'person@example.test',
            'email_verified' => true,
            'name' => 'Person Example',
            'locale' => 'en-US',
        ];
    }
}

final class FakeUnverifiedOidcClient extends OpenIDConnectClient
{
    public function authenticate(): bool
    {
        return true;
    }

    public function getVerifiedClaims(string $attribute = null): object
    {
        return (object) [
            'iss' => 'https://issuer.example.test',
            'sub' => 'subject-123',
            'email' => 'person@example.test',
        ];
    }
}
