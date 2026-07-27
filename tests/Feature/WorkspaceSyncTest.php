<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class WorkspaceSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_sync_endpoint_returns_modified_notes(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $tenant = Tenant::create(['slug' => 'default', 'name' => 'Default']);
        $workspace = Workspace::create([
            'tenant_id' => $tenant->id,
            'slug' => 'main',
            'name' => 'Main',
            'vault_path' => storage_path('app/vaults/sync_test'),
        ]);

        $response = $this->actingAs($admin)
            ->getJson("/api/workspaces/{$workspace->id}/sync");

        $response->assertOk()
            ->assertJsonStructure([
                'workspace' => ['id', 'slug', 'name'],
                'sync_timestamp',
                'notes',
            ]);
    }
}
