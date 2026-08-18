<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class WorkspacePublishTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_publish_compiles_static_html_pages(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'locale' => 'en']);
        $tenant = Tenant::create(['slug' => 'default', 'name' => 'Default']);
        
        $vaultDir = storage_path('app/vaults/publish_test');
        if (! is_dir($vaultDir)) {
            mkdir($vaultDir, 0755, true);
        }
        file_put_contents($vaultDir.'/index.md', '# Welcome to Jotter');

        $workspace = Workspace::create([
            'tenant_id' => $tenant->id,
            'slug' => 'main',
            'name' => 'Main',
            'vault_path' => $vaultDir,
        ]);

        // Trigger note index
        $this->artisan('vault:reindex', ['--workspace' => $workspace->id]);

        $response = $this->actingAs($admin)
            ->postJson("/api/workspaces/{$workspace->id}/publish");

        $response->assertOk()
            ->assertJsonStructure([
                'message',
                'workspace',
                'notes_published',
                'site_url',
            ]);

        $publishedFile = storage_path('app/public/sites/main/index.html');
        $this->assertFileExists($publishedFile);

        $html = file_get_contents($publishedFile);
        $this->assertStringContainsString('<html lang="en" dir="ltr">', $html);
        $this->assertStringContainsString('<meta charset="utf-8">', $html);
        $this->assertStringContainsString('<meta name="viewport" content="width=device-width, initial-scale=1">', $html);
        $this->assertStringContainsString('<meta name="theme-color" content="#FFFFFF">', $html);
        $this->assertStringContainsString('<meta name="color-scheme" content="light dark">', $html);
        $this->assertStringContainsString('publish.css', $html);
        $this->assertStringContainsString('publish-theme.js', $html);
        $this->assertStringContainsString('id="publish-theme-preference"', $html);
        $this->assertStringContainsString('value="system"', $html);
        $this->assertStringContainsString('Theme preference', $html);

        $this->assertFileExists(storage_path('app/public/sites/main/publish.css'));
        $this->assertFileExists(storage_path('app/public/sites/main/publish-theme.js'));
        foreach ([
            'inter-400.woff2',
            'inter-600.woff2',
            'inter-700.woff2',
            'source-serif-4-700.woff2',
            'ibm-plex-mono-400.woff2',
        ] as $font) {
            $this->assertFileExists(storage_path("app/public/sites/main/fonts/{$font}"));
        }
        $themeScript = file_get_contents(storage_path('app/public/sites/main/publish-theme.js'));
        $this->assertStringContainsString("setAttribute('data-theme', theme)", $themeScript);
        $css = file_get_contents(storage_path('app/public/sites/main/publish.css'));
        $this->assertStringContainsString('--color-action-primary: #1A6DC1', $css);
        $this->assertStringContainsString("font-family: 'Source Serif 4'", $css);
        $this->assertStringContainsString("url('fonts/source-serif-4-700.woff2')", $css);
        $this->assertStringContainsString("font-family: 'IBM Plex Mono'", $css);
        $this->assertStringContainsString("url('fonts/ibm-plex-mono-400.woff2')", $css);
        $this->assertStringContainsString('Source Serif 4', $css);
        $this->assertStringContainsString('IBM Plex Mono', $css);
        $this->assertStringNotContainsString('Open Sans', $css);
    }

    public function test_index_page_is_generated_even_when_no_note_is_at_that_path(): void
    {
        // Regression: the previous test's fixture happened to be named
        // index.md, which made index.html exist as a side effect of the
        // per-note loop — masking that the returned site_url (always
        // pointing at index.html) had no guarantee of ever existing for any
        // other vault layout. Here nothing is named "index".
        $admin = User::factory()->create(['is_admin' => true]);
        $tenant = Tenant::create(['slug' => 'default', 'name' => 'Default']);

        $vaultDir = storage_path('app/vaults/publish_test_no_index');
        if (! is_dir($vaultDir)) {
            mkdir($vaultDir, 0755, true);
        }
        file_put_contents($vaultDir.'/hello.md', '# Hello World');
        file_put_contents($vaultDir.'/goodbye.md', '# Goodbye World');

        $workspace = Workspace::create([
            'tenant_id' => $tenant->id,
            'slug' => 'no-index',
            'name' => 'No Index',
            'vault_path' => $vaultDir,
        ]);

        $this->artisan('vault:reindex', ['--workspace' => $workspace->id]);

        $response = $this->actingAs($admin)
            ->postJson("/api/workspaces/{$workspace->id}/publish");

        $response->assertOk()->assertJsonPath('notes_published', 2);

        $indexFile = storage_path('app/public/sites/no-index/index.html');
        $this->assertFileExists($indexFile);

        $indexHtml = file_get_contents($indexFile);
        $this->assertStringContainsString('hello.html', $indexHtml);
        $this->assertStringContainsString('goodbye.html', $indexHtml);
        $this->assertStringContainsString('Hello World', $indexHtml);
    }

    public function test_workspace_publish_falls_back_when_a_font_source_is_unavailable(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $tenant = Tenant::create(['slug' => 'default', 'name' => 'Default']);
        $slug = 'fallback-font-'.bin2hex(random_bytes(4));
        $vaultDir = storage_path('app/vaults/'.$slug);
        mkdir($vaultDir, 0755, true);
        file_put_contents($vaultDir.'/index.md', '# Fallback font');

        $workspace = Workspace::create([
            'tenant_id' => $tenant->id,
            'slug' => $slug,
            'name' => 'Fallback font',
            'vault_path' => $vaultDir,
        ]);

        $this->artisan('vault:reindex', ['--workspace' => $workspace->id]);

        $source = base_path('frontend/src/assets/fonts/ibm-plex-mono-400.woff2');
        $temporarySource = $source.'.missing-for-test';
        $this->assertFileExists($source);
        $this->assertTrue(rename($source, $temporarySource));

        try {
            $response = $this->actingAs($admin)
                ->postJson("/api/workspaces/{$workspace->id}/publish");

            $response->assertOk();
            $this->assertFileExists(storage_path("app/public/sites/{$slug}/index.html"));
            $this->assertFileDoesNotExist(
                storage_path("app/public/sites/{$slug}/fonts/ibm-plex-mono-400.woff2")
            );
        } finally {
            $this->assertTrue(rename($temporarySource, $source));
        }
    }

    public function test_workspace_publish_degrades_external_embed_urls_to_links(): void
    {
        config(['jotter.external_embed_domains' => ['youtube.com']]);
        $admin = User::factory()->create(['is_admin' => true, 'locale' => 'en']);
        $tenant = Tenant::create(['slug' => 'default', 'name' => 'Default']);
        $slug = 'publish-external-'.bin2hex(random_bytes(4));
        $vaultDir = storage_path('app/vaults/'.$slug);
        mkdir($vaultDir, 0755, true);
        file_put_contents($vaultDir.'/external.md', "# External\n\nhttps://www.youtube.com/embed/abc\n");

        $workspace = Workspace::create([
            'tenant_id' => $tenant->id,
            'slug' => $slug,
            'name' => 'External',
            'vault_path' => $vaultDir,
        ]);

        $this->artisan('vault:reindex', ['--workspace' => $workspace->id]);

        $this->actingAs($admin)
            ->postJson("/api/workspaces/{$workspace->id}/publish")
            ->assertOk();

        $html = file_get_contents(storage_path("app/public/sites/{$slug}/external.html"));
        $this->assertStringNotContainsString('<iframe', $html);
        $this->assertStringContainsString('https://www.youtube.com/embed/abc', $html);
    }
}
