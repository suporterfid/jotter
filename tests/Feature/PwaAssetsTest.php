<?php

namespace Tests\Feature;

use Tests\TestCase;

final class PwaAssetsTest extends TestCase
{
    public function test_manifest_exposes_standalone_jotter_shell_metadata(): void
    {
        $manifestPath = public_path('manifest.webmanifest');

        $this->assertFileExists($manifestPath);
        $manifest = json_decode(file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('Jotter', $manifest['name']);
        $this->assertSame('Jotter', $manifest['short_name']);
        $this->assertSame('/', $manifest['start_url']);
        $this->assertSame('/', $manifest['scope']);
        $this->assertSame('standalone', $manifest['display']);
        $this->assertSame('#FFFFFF', $manifest['theme_color']);
        $this->assertSame('#FFFFFF', $manifest['background_color']);
        $this->assertNotEmpty($manifest['icons']);
        $this->assertSame('/favicon.svg', $manifest['icons'][0]['src']);
        $this->assertSame('image/svg+xml', $manifest['icons'][0]['type']);
    }

    public function test_app_shells_link_the_manifest_without_regressing_existing_metadata(): void
    {
        $blade = file_get_contents(resource_path('views/app.blade.php'));
        $vite = file_get_contents(base_path('frontend/index.html'));

        foreach ([$blade, $vite] as $shell) {
            $this->assertStringContainsString('rel="manifest" href="/manifest.webmanifest"', $shell);
            $this->assertStringContainsString('/favicon.ico', $shell);
            $this->assertStringContainsString('/favicon.svg', $shell);
            $this->assertStringContainsString('/apple-touch-icon.png', $shell);
            $this->assertStringContainsString('social-card.png', $shell);
            $this->assertStringContainsString('theme-color', $shell);
            $this->assertStringContainsString('#191919', $shell);
        }
    }

    public function test_offline_shell_and_service_worker_are_static_and_source_copies_match(): void
    {
        $sourceRoot = base_path('frontend/public');
        $deployRoot = public_path();

        foreach (['manifest.webmanifest', 'offline.html', 'service-worker.js'] as $asset) {
            $source = $sourceRoot.'/'.$asset;
            $deploy = $deployRoot.'/'.$asset;

            $this->assertFileExists($source);
            $this->assertFileExists($deploy);
            $this->assertSame(file_get_contents($source), file_get_contents($deploy), "PWA asset drifted: {$asset}");
        }

        $offline = file_get_contents($deployRoot.'/offline.html');
        $this->assertStringContainsString('Jotter is offline', $offline);
        $this->assertStringNotContainsString('/api/', $offline);

        $worker = file_get_contents($deployRoot.'/service-worker.js');
        $this->assertMatchesRegularExpression('/jotter-shell-v[0-9-]+/', $worker);
        $this->assertStringContainsString("const BYPASSED_PREFIXES = ['/api/'", $worker);
        $this->assertStringContainsString("'/offline.html'", $worker);
        $this->assertStringContainsString('network-first', strtolower($worker));
        $this->assertStringContainsString('cache-first', strtolower($worker));
    }
}
