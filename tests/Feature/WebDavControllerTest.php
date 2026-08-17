<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\Membership;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class WebDavControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_webdav_propfind_returns_multistatus_xml(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $tenant = Tenant::create(['slug' => 'default', 'name' => 'Default']);
        $vaultPath = storage_path('app/vaults/webdav_test');
        @mkdir($vaultPath, 0755, true);

        $workspace = Workspace::create([
            'tenant_id' => $tenant->id,
            'slug' => 'main',
            'name' => 'Main',
            'vault_path' => $vaultPath,
        ]);

        $response = $this->withoutExceptionHandling()
            ->actingAs($admin)
            ->call('PROPFIND', "/api/webdav/{$workspace->id}/welcome.md");

        $response->assertStatus(207);
        $this->assertStringContainsString('multistatus', $response->getContent());
    }

    public function test_webdav_options_and_mkcol_methods(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $tenant = Tenant::create(['slug' => 'default', 'name' => 'Default']);
        $vaultPath = storage_path('app/vaults/webdav_test_options');
        @mkdir($vaultPath, 0755, true);

        $workspace = Workspace::create([
            'tenant_id' => $tenant->id,
            'slug' => 'main',
            'name' => 'Main',
            'vault_path' => $vaultPath,
        ]);

        $optionsRes = $this->actingAs($admin)->call('OPTIONS', "/api/webdav/{$workspace->id}");
        $optionsRes->assertStatus(200)->assertHeader('DAV', '1, 2');

        $mkcolRes = $this->actingAs($admin)->call('MKCOL', "/api/webdav/{$workspace->id}/subfolder");
        $mkcolRes->assertStatus(201);
    }

    public function test_viewer_can_read_webdav_but_cannot_write_or_delete(): void
    {
        $viewer = User::factory()->create(['is_admin' => false]);
        $tenant = Tenant::create(['slug' => 'viewer-webdav', 'name' => 'Viewer WebDAV']);
        $vaultPath = storage_path('app/vaults/webdav_viewer_'.uniqid());
        @mkdir($vaultPath, 0755, true);
        file_put_contents($vaultPath.'/existing.md', '# Existing');

        $workspace = Workspace::create([
            'tenant_id' => $tenant->id,
            'slug' => 'viewer-webdav',
            'name' => 'Viewer WebDAV',
            'vault_path' => $vaultPath,
        ]);

        Membership::create([
            'subject_id' => (string) $viewer->id,
            'tenant_id' => $tenant->id,
            'workspace_id' => $workspace->id,
            'role' => 'viewer',
        ]);

        $this->actingAs($viewer)
            ->call('PROPFIND', "/api/webdav/{$workspace->id}/existing.md")
            ->assertStatus(207);

        $this->actingAs($viewer)
            ->call('PUT', "/api/webdav/{$workspace->id}/new.md", [], [], [], [], '# New')
            ->assertForbidden();

        $this->actingAs($viewer)
            ->call('MKCOL', "/api/webdav/{$workspace->id}/new-folder")
            ->assertForbidden();

        $this->actingAs($viewer)
            ->call('DELETE', "/api/webdav/{$workspace->id}/existing.md")
            ->assertForbidden();

        $this->assertFileExists($vaultPath.'/existing.md');
        $this->assertFileDoesNotExist($vaultPath.'/new.md');
        $this->assertDirectoryDoesNotExist($vaultPath.'/new-folder');
    }
}
