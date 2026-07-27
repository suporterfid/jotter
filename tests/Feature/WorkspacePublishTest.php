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
        $workspace = Workspace::create([
            'tenant_id' => $tenant->id,
            'slug' => 'main',
            'name' => 'Main',
            'vault_path' => storage_path('app/vaults/publish_test'),
        ]);

        $response = $this->actingAs($admin)
            ->postJson("/api/workspaces/{$workspace->id}/publish");

        $response->assertOk()
            ->assertJsonStructure([
                'message',
                'workspace',
                'notes_published',
                'site_url',
            ]);
    }
}
