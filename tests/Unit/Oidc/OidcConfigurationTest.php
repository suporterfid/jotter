<?php

namespace Tests\Unit\Oidc;

use Tests\TestCase;

class OidcConfigurationTest extends TestCase
{
    public function test_oidc_configuration_exposes_the_provider_contract(): void
    {
        $oidc = config('jotter.oidc');

        $this->assertIsArray($oidc);
        foreach ([
            'issuer_url',
            'client_id',
            'client_secret',
            'redirect_uri',
            'scopes',
            'post_login_redirect_uri',
            'allow_insecure_http',
            'configured',
        ] as $key) {
            $this->assertArrayHasKey($key, $oidc);
        }

        config([
            'jotter.auth_provider' => 'oidc',
            'jotter.oidc.issuer_url' => 'https://issuer.example.test',
            'jotter.oidc.client_id' => 'jotter-client',
            'jotter.oidc.client_secret' => 'secret-from-environment',
            'jotter.oidc.redirect_uri' => 'https://jotter.example.test/api/auth/oidc/callback',
            'jotter.oidc.scopes' => ['openid', 'profile', 'email'],
            'jotter.oidc.post_login_redirect_uri' => 'https://jotter.example.test',
            'jotter.oidc.allow_insecure_http' => false,
        ]);

        $this->assertSame('oidc', config('jotter.auth_provider'));
        $this->assertSame('https://issuer.example.test', config('jotter.oidc.issuer_url'));
        $this->assertSame('jotter-client', config('jotter.oidc.client_id'));
        $this->assertSame('secret-from-environment', config('jotter.oidc.client_secret'));
        $this->assertSame(
            'https://jotter.example.test/api/auth/oidc/callback',
            config('jotter.oidc.redirect_uri'),
        );
        $this->assertSame(['openid', 'profile', 'email'], config('jotter.oidc.scopes'));
        $this->assertSame('https://jotter.example.test', config('jotter.oidc.post_login_redirect_uri'));
        $this->assertFalse(config('jotter.oidc.allow_insecure_http'));
    }

    public function test_oidc_is_not_silently_configured_when_required_settings_are_missing(): void
    {
        config([
            'jotter.oidc.issuer_url' => null,
            'jotter.oidc.client_id' => null,
            'jotter.oidc.client_secret' => null,
            'jotter.oidc.redirect_uri' => null,
        ]);

        $this->assertFalse(config('jotter.oidc.configured'));
    }

    public function test_local_remains_the_default_auth_provider(): void
    {
        $this->assertSame('local', config('jotter.auth_provider'));
    }
}
