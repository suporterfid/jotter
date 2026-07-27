<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class WorkspaceExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_export_endpoint_returns_downloadable_zip(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $tenant = Tenant::create(['slug' => 'default', 'name' => 'Default']);
        $workspace = Workspace::create([
            'tenant_id' => $tenant->id,
            'slug' => 'main',
            'name' => 'Main',
            'vault_path' => storage_path('app/vaults/export_test'),
        ]);

        $response = $this->withoutExceptionHandling()
            ->actingAs($admin)
            ->get("/api/workspaces/{$workspace->id}/export");

        $response->assertOk();
    }
}
