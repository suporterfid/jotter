<?php

namespace Tests\Feature;

use App\Domain\Vault\VaultStorage;
use App\Models\Note;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class WorkspaceNoteActivityControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeNote(): array
    {
        $tenant = Tenant::create(['slug' => 'activity-test-'.uniqid(), 'name' => 'Activity Test']);
        $vaultPath = storage_path('app/vaults/activity_test_'.uniqid());
        mkdir($vaultPath, 0755, true);

        $workspace = Workspace::create([
            'tenant_id' => $tenant->id,
            'slug' => 'activity-'.uniqid(),
            'name' => 'Activity Workspace',
            'vault_path' => $vaultPath,
        ]);

        /** @var VaultStorage $storage */
        $storage = app(VaultStorage::class);
        $storage->write($workspace, 'card.md', '# Card');
        $note = Note::where('workspace_id', $workspace->id)->where('path', 'card.md')->firstOrFail();

        return [$workspace, $note];
    }

    public function test_setting_a_property_records_an_activity_entry_for_the_note(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        [$workspace, $note] = $this->makeNote();

        $this->actingAs($admin)->postJson(
            "/api/workspaces/{$workspace->id}/notes/{$note->id}/properties",
            ['name' => 'status', 'value' => 'done']
        )->assertOk();

        $response = $this->actingAs($admin)->getJson("/api/workspaces/{$workspace->id}/notes/{$note->id}/activity");

        $response->assertOk()
            ->assertJsonPath('data.0.event', 'note.updated')
            ->assertJsonPath('data.0.metadata.action', 'property_set')
            ->assertJsonPath('data.0.metadata.name', 'status')
            ->assertJsonPath('data.0.metadata.value', 'done');
    }

    public function test_toggling_a_checklist_item_records_an_activity_entry(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        [$workspace, $note] = $this->makeNote();

        $created = $this->actingAs($admin)->postJson(
            "/api/workspaces/{$workspace->id}/notes/{$note->id}/checklist-items",
            ['text' => 'Ship it']
        )->assertCreated();
        $itemId = $created->json('data.id');

        $this->actingAs($admin)->putJson(
            "/api/workspaces/{$workspace->id}/notes/{$note->id}/checklist-items/{$itemId}",
            ['done' => true]
        )->assertOk();

        $response = $this->actingAs($admin)->getJson("/api/workspaces/{$workspace->id}/notes/{$note->id}/activity");

        $response->assertOk();
        $events = collect($response->json('data'))->pluck('metadata.action');
        $this->assertTrue($events->contains('checklist_item_created'));
        $this->assertTrue($events->contains('checklist_item_checked'));
    }

    public function test_activity_is_scoped_to_the_note_not_the_whole_workspace(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        [$workspace, $noteA] = $this->makeNote();

        /** @var VaultStorage $storage */
        $storage = app(VaultStorage::class);
        $storage->write($workspace, 'other.md', '# Other');
        $noteB = Note::where('workspace_id', $workspace->id)->where('path', 'other.md')->firstOrFail();

        $this->actingAs($admin)->postJson(
            "/api/workspaces/{$workspace->id}/notes/{$noteB->id}/properties",
            ['name' => 'status', 'value' => 'done']
        )->assertOk();

        $response = $this->actingAs($admin)->getJson("/api/workspaces/{$workspace->id}/notes/{$noteA->id}/activity");

        $response->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_cannot_view_activity_in_a_workspace_the_user_is_unauthorized_for(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        [$workspace, $note] = $this->makeNote();

        $response = $this->actingAs($user)->getJson("/api/workspaces/{$workspace->id}/notes/{$note->id}/activity");

        $response->assertStatus(403);
    }
}
