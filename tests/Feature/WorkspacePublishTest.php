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
        $admin = User::factory()->create(['is_admin' => true]);
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
        $this->assertStringContainsString('<html lang="en">', $html);
        $this->assertStringContainsString('<meta charset="utf-8">', $html);
        $this->assertStringContainsString('<meta name="viewport" content="width=device-width, initial-scale=1">', $html);
        $this->assertStringContainsString('<meta name="theme-color" content="#000000">', $html);
        $this->assertStringContainsString('<meta name="color-scheme" content="dark">', $html);
        $this->assertStringContainsString('publish.css', $html);

        $this->assertFileExists(storage_path('app/public/sites/main/publish.css'));
    }
}
