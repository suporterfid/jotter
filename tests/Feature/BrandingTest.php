<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BrandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_auth_config_exposes_default_branding(): void
    {
        $this->getJson('/api/auth/config')
            ->assertOk()
            ->assertJsonPath('data.brand', [
                'name' => config('app.name'),
                'logo_url' => null,
                'support_url' => null,
                'terms_url' => null,
                'privacy_url' => null,
                'powered_by' => true,
                'powered_by_url' => 'https://github.com/suporterfid/jotter',
            ]);
    }

    public function test_auth_config_exposes_operator_branding(): void
    {
        config(['jotter.brand' => [
            'name' => 'Cadernia',
            'logo_url' => 'https://cdn.example.com/cadernia.svg',
            'support_url' => 'https://cadernia.example.com/support',
            'terms_url' => 'https://cadernia.example.com/terms',
            'privacy_url' => 'https://cadernia.example.com/privacy',
            'powered_by' => false,
        ]]);

        $this->getJson('/api/auth/config')
            ->assertOk()
            ->assertJsonPath('data.brand.name', 'Cadernia')
            ->assertJsonPath('data.brand.logo_url', 'https://cdn.example.com/cadernia.svg')
            ->assertJsonPath('data.brand.terms_url', 'https://cadernia.example.com/terms')
            ->assertJsonPath('data.brand.privacy_url', 'https://cadernia.example.com/privacy')
            ->assertJsonPath('data.brand.support_url', 'https://cadernia.example.com/support')
            ->assertJsonPath('data.brand.powered_by', false);
    }

    public function test_empty_brand_values_fall_back_to_defaults(): void
    {
        config(['jotter.brand' => ['name' => '   ', 'logo_url' => '', 'powered_by' => true]]);

        $this->getJson('/api/auth/config')
            ->assertOk()
            ->assertJsonPath('data.brand.name', config('app.name'))
            ->assertJsonPath('data.brand.logo_url', null);
    }

    public function test_app_shell_uses_the_brand_name_in_title_and_open_graph(): void
    {
        config(['jotter.brand' => ['name' => 'Cadernia', 'logo_url' => 'https://cdn.example.com/c.png']]);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('<title>Cadernia</title>', $html);
        $this->assertStringContainsString('<meta property="og:title" content="Cadernia">', $html);
        $this->assertStringContainsString('<meta property="og:image" content="https://cdn.example.com/c.png">', $html);
    }

    public function test_app_shell_defaults_to_jotter(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('<title>'.config('app.name').'</title>', $html);
        $this->assertStringContainsString('social-card.png', $html);
    }

    public function test_published_pages_carry_brand_links_and_powered_by(): void
    {
        config(['jotter.brand' => [
            'name' => 'Cadernia',
            'terms_url' => 'https://cadernia.example.com/terms',
            'privacy_url' => 'https://cadernia.example.com/privacy',
            'support_url' => null,
            'powered_by' => true,
        ]]);

        $html = view('publish.page', [
            'locale' => 'en',
            'direction' => 'ltr',
            'assetPrefix' => '',
            'title' => 'Hello',
            'html' => '<p>Body</p>',
            'themeLabels' => ['preference' => 'Theme', 'system' => 'System', 'light' => 'Light', 'dark' => 'Dark'],
        ])->render();

        $this->assertStringContainsString('class="publish-footer" data-brand="Cadernia"', $html);
        $this->assertStringContainsString('href="https://cadernia.example.com/terms"', $html);
        $this->assertStringContainsString('href="https://cadernia.example.com/privacy"', $html);
        $this->assertStringNotContainsString('>Support<', $html);
        $this->assertStringContainsString('Powered by Jotter', $html);
    }

    public function test_published_pages_omit_the_footer_when_nothing_is_configured_and_powered_by_is_off(): void
    {
        config(['jotter.brand' => ['powered_by' => false]]);

        $html = view('publish.page', [
            'locale' => 'en',
            'direction' => 'ltr',
            'assetPrefix' => '',
            'title' => 'Hello',
            'html' => '<p>Body</p>',
            'themeLabels' => ['preference' => 'Theme', 'system' => 'System', 'light' => 'Light', 'dark' => 'Dark'],
        ])->render();

        $this->assertStringNotContainsString('publish-footer', $html);
    }
}
