<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminWorkspaceCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admin_is_forbidden(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $tenant = Tenant::create(['slug' => 'default', 'name' => 'Default']);

        $res = $this->actingAs($user)->postJson('/api/admin/workspaces', [
            'tenant_id' => $tenant->id,
            'slug' => 'test-ws',
            'name' => 'Test WS',
            'vault_path' => storage_path('app/vaults/test_ws'),
        ]);

        $res->assertStatus(403);
    }

    public function test_admin_can_create_and_archive_workspace(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $tenant = Tenant::create(['slug' => 'default', 'name' => 'Default']);

        $vaultPath = storage_path('app/vaults/ws_crud_test_'.uniqid());

        // 1. Create workspace
        $res = $this->actingAs($admin)->postJson('/api/admin/workspaces', [
            'tenant_id' => $tenant->id,
            'slug' => 'valid-ws',
            'name' => 'Valid Workspace',
            'vault_path' => $vaultPath,
        ]);

        $res->assertCreated();
        $wsId = $res->json('data.id');

        $this->assertDirectoryExists($vaultPath);
        file_put_contents("{$vaultPath}/note.md", "# Test Note");

        // 2. Archive workspace
        $archiveRes = $this->actingAs($admin)->postJson("/api/admin/workspaces/{$wsId}/archive");
        $archiveRes->assertOk();

        // Assert files on disk remain completely untouched
        $this->assertFileExists("{$vaultPath}/note.md");
    }

    public function test_vault_path_outside_base_directory_is_rejected_and_audited(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $tenant = Tenant::create(['slug' => 'default', 'name' => 'Default']);

        $res = $this->actingAs($admin)->postJson('/api/admin/workspaces', [
            'tenant_id' => $tenant->id,
            'slug' => 'unsafe-ws',
            'name' => 'Unsafe Workspace',
            'vault_path' => '/etc/unsafe_vault_root',
        ]);

        $res->assertStatus(422)
            ->assertJsonValidationErrors(['vault_path']);

        $this->assertDatabaseHas('audit_log', [
            'event' => 'vault.root_rejected',
        ]);
    }

    public function test_vault_path_nesting_collision_is_rejected_and_audited(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $tenant = Tenant::create(['slug' => 'default', 'name' => 'Default']);

        $parentVault = storage_path('app/vaults/parent_vault_'.uniqid());
        @mkdir($parentVault, 0755, true);

        Workspace::create([
            'tenant_id' => $tenant->id,
            'slug' => 'parent-ws',
            'name' => 'Parent Workspace',
            'vault_path' => $parentVault,
        ]);

        // Attempt to create a nested workspace inside parent vault
        $res = $this->actingAs($admin)->postJson('/api/admin/workspaces', [
            'tenant_id' => $tenant->id,
            'slug' => 'nested-ws',
            'name' => 'Nested Workspace',
            'vault_path' => "{$parentVault}/child",
        ]);

        $res->assertStatus(422)
            ->assertJsonValidationErrors(['vault_path']);

        $this->assertDatabaseHas('audit_log', [
            'event' => 'vault.root_rejected',
        ]);
    }
}
