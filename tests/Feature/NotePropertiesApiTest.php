<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotePropertiesApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_properties_api_and_front_matter_write_through(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $tenant = Tenant::create(['slug' => 'default', 'name' => 'Default']);
        $vaultPath = storage_path('app/vaults/prop_api_test_'.uniqid());
        @mkdir($vaultPath, 0755, true);

        $workspace = Workspace::create([
            'tenant_id' => $tenant->id,
            'slug' => 'default',
            'name' => 'Default Workspace',
            'vault_path' => $vaultPath,
        ]);

        // 1. Create a note without front-matter
        $createRes = $this->actingAs($admin)->postJson("/api/workspaces/{$workspace->id}/notes", [
            'path' => 'plain.md',
            'content' => "# Plain Note Body",
        ]);
        $createRes->assertCreated();
        $noteId = $createRes->json('data.id');

        // 2. Add first property (should gain front-matter block on disk)
        $setRes = $this->actingAs($admin)->postJson("/api/workspaces/{$workspace->id}/notes/{$noteId}/properties", [
            'name' => 'status',
            'value' => 'active',
        ]);
        $setRes->assertOk();
        $this->assertDatabaseHas('note_properties', [
            'note_id' => $noteId,
            'name' => 'status',
            'value_string' => 'active',
        ]);
        $this->assertStringContainsString('status: active', file_get_contents("{$vaultPath}/plain.md"));

        // 3. Add second property (numeric)
        $setRes2 = $this->actingAs($admin)->postJson("/api/workspaces/{$workspace->id}/notes/{$noteId}/properties", [
            'name' => 'priority',
            'value' => 5,
        ]);
        $setRes2->assertOk();

        // 4. Test workspace distinct properties endpoint
        $propListRes = $this->actingAs($admin)->getJson("/api/workspaces/{$workspace->id}/properties");
        $propListRes->assertOk()->assertJsonCount(2, 'data');

        // 5. Delete property
        $delRes = $this->actingAs($admin)->deleteJson("/api/workspaces/{$workspace->id}/notes/{$noteId}/properties/status");
        $delRes->assertOk();
        $this->assertDatabaseMissing('note_properties', [
            'note_id' => $noteId,
            'name' => 'status',
        ]);
        $this->assertStringNotContainsString('status: active', file_get_contents("{$vaultPath}/plain.md"));
    }
}
