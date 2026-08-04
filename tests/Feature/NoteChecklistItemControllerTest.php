<?php

namespace Tests\Feature;

use App\Domain\Vault\VaultStorage;
use App\Models\Note;
use App\Models\NoteChecklistItem;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class NoteChecklistItemControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeNote(): array
    {
        $tenant = Tenant::create(['slug' => 'checklist-test-'.uniqid(), 'name' => 'Checklist Test']);
        $vaultPath = storage_path('app/vaults/checklist_test_'.uniqid());
        mkdir($vaultPath, 0755, true);

        $workspace = Workspace::create([
            'tenant_id' => $tenant->id,
            'slug' => 'checklist-'.uniqid(),
            'name' => 'Checklist Workspace',
            'vault_path' => $vaultPath,
        ]);

        /** @var VaultStorage $storage */
        $storage = app(VaultStorage::class);
        $storage->write($workspace, 'card.md', '# Card');
        $note = Note::where('workspace_id', $workspace->id)->where('path', 'card.md')->firstOrFail();

        return [$workspace, $note];
    }

    public function test_creates_and_lists_checklist_items_in_order(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        [$workspace, $note] = $this->makeNote();

        $this->actingAs($admin)->postJson("/api/workspaces/{$workspace->id}/notes/{$note->id}/checklist-items", ['text' => 'First'])
            ->assertCreated()
            ->assertJsonPath('data.text', 'First')
            ->assertJsonPath('data.done', false);

        $this->actingAs($admin)->postJson("/api/workspaces/{$workspace->id}/notes/{$note->id}/checklist-items", ['text' => 'Second'])
            ->assertCreated();

        $response = $this->actingAs($admin)->getJson("/api/workspaces/{$workspace->id}/notes/{$note->id}/checklist-items");

        $response->assertOk()
            ->assertJsonPath('data.0.text', 'First')
            ->assertJsonPath('data.1.text', 'Second');
    }

    public function test_toggles_an_items_done_state(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        [$workspace, $note] = $this->makeNote();
        $item = NoteChecklistItem::create(['note_id' => $note->id, 'text' => 'Ship it', 'done' => false, 'sort_position' => 0]);

        $response = $this->actingAs($admin)->putJson(
            "/api/workspaces/{$workspace->id}/notes/{$note->id}/checklist-items/{$item->id}",
            ['done' => true]
        );

        $response->assertOk()->assertJsonPath('data.done', true);
        $this->assertTrue($item->fresh()->done);
    }

    public function test_deletes_an_item(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        [$workspace, $note] = $this->makeNote();
        $item = NoteChecklistItem::create(['note_id' => $note->id, 'text' => 'Doomed', 'done' => false, 'sort_position' => 0]);

        $response = $this->actingAs($admin)->deleteJson("/api/workspaces/{$workspace->id}/notes/{$note->id}/checklist-items/{$item->id}");

        $response->assertNoContent();
        $this->assertDatabaseMissing('note_checklist_items', ['id' => $item->id]);
    }

    public function test_the_collections_endpoint_derives_checklist_progress_from_this_structure_not_markdown(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        [$workspace, $note] = $this->makeNote();

        // The note body has no markdown task-list syntax at all — checklist
        // progress must come purely from note_checklist_items.
        NoteChecklistItem::create(['note_id' => $note->id, 'text' => 'A', 'done' => true, 'sort_position' => 0]);
        NoteChecklistItem::create(['note_id' => $note->id, 'text' => 'B', 'done' => false, 'sort_position' => 1]);

        $response = $this->actingAs($admin)->getJson("/api/workspaces/{$workspace->id}/collections");

        $response->assertOk()
            ->assertJsonPath('data.0.checklist_total', 2)
            ->assertJsonPath('data.0.checklist_done', 1);
    }

    public function test_cannot_manage_checklist_items_in_a_workspace_the_user_is_unauthorized_for(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        [$workspace, $note] = $this->makeNote();

        $response = $this->actingAs($user)->getJson("/api/workspaces/{$workspace->id}/notes/{$note->id}/checklist-items");

        $response->assertStatus(403);
    }
}
