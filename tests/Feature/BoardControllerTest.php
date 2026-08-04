<?php

namespace Tests\Feature;

use App\Models\Board;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BoardControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeWorkspace(): Workspace
    {
        $tenant = Tenant::create(['slug' => 'board-test-'.uniqid(), 'name' => 'Board Test']);
        $vaultPath = storage_path('app/vaults/board_test_'.uniqid());
        mkdir($vaultPath, 0755, true);

        return Workspace::create([
            'tenant_id' => $tenant->id,
            'slug' => 'board-'.uniqid(),
            'name' => 'Board Workspace',
            'vault_path' => $vaultPath,
        ]);
    }

    public function test_lists_boards_for_a_workspace_ordered_by_sort_position(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $workspace = $this->makeWorkspace();
        Board::create(['workspace_id' => $workspace->id, 'name' => 'Second', 'sort_position' => 1]);
        Board::create(['workspace_id' => $workspace->id, 'name' => 'First', 'sort_position' => 0]);

        $response = $this->actingAs($admin)->getJson("/api/workspaces/{$workspace->id}/boards");

        $response->assertOk()
            ->assertJsonPath('data.0.name', 'First')
            ->assertJsonPath('data.1.name', 'Second');
    }

    public function test_creates_a_board(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $workspace = $this->makeWorkspace();

        $response = $this->actingAs($admin)->postJson("/api/workspaces/{$workspace->id}/boards", [
            'name' => 'Sprint Board',
            'group_property' => 'status',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Sprint Board')
            ->assertJsonPath('data.group_property', 'status');

        $this->assertDatabaseHas('boards', ['workspace_id' => $workspace->id, 'name' => 'Sprint Board']);
    }

    public function test_creating_a_board_requires_a_name(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $workspace = $this->makeWorkspace();

        $response = $this->actingAs($admin)->postJson("/api/workspaces/{$workspace->id}/boards", []);

        $response->assertStatus(422);
    }

    public function test_updates_a_boards_view_configuration(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $workspace = $this->makeWorkspace();
        $board = Board::create(['workspace_id' => $workspace->id, 'name' => 'Original']);

        $response = $this->actingAs($admin)->putJson("/api/workspaces/{$workspace->id}/boards/{$board->id}", [
            'name' => 'Renamed',
            'group_property' => 'priority',
            'filter_property' => 'assignee',
            'filter_value' => 'alice',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Renamed')
            ->assertJsonPath('data.group_property', 'priority')
            ->assertJsonPath('data.filter_property', 'assignee')
            ->assertJsonPath('data.filter_value', 'alice');
    }

    public function test_persists_column_configuration(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $workspace = $this->makeWorkspace();
        $board = Board::create(['workspace_id' => $workspace->id, 'name' => 'Original']);

        $columnConfig = [
            ['key' => 'done', 'label' => 'Done', 'color' => '#22c55e', 'wip_limit' => 3, 'collapsed' => false],
            ['key' => 'draft', 'label' => 'Draft', 'color' => null, 'wip_limit' => null, 'collapsed' => true],
        ];

        $response = $this->actingAs($admin)->putJson("/api/workspaces/{$workspace->id}/boards/{$board->id}", [
            'column_config' => $columnConfig,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.column_config.0.key', 'done')
            ->assertJsonPath('data.column_config.0.wip_limit', 3)
            ->assertJsonPath('data.column_config.1.collapsed', true);

        $this->assertEquals($columnConfig, $board->fresh()->column_config);
    }

    public function test_persists_swimlane_property(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $workspace = $this->makeWorkspace();
        $board = Board::create(['workspace_id' => $workspace->id, 'name' => 'Original']);

        $response = $this->actingAs($admin)->putJson("/api/workspaces/{$workspace->id}/boards/{$board->id}", [
            'swimlane_property' => 'assignee',
        ]);

        $response->assertOk()->assertJsonPath('data.swimlane_property', 'assignee');
        $this->assertSame('assignee', $board->fresh()->swimlane_property);
    }

    public function test_deletes_a_board(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $workspace = $this->makeWorkspace();
        $board = Board::create(['workspace_id' => $workspace->id, 'name' => 'Doomed']);

        $response = $this->actingAs($admin)->deleteJson("/api/workspaces/{$workspace->id}/boards/{$board->id}");

        $response->assertNoContent();
        $this->assertDatabaseMissing('boards', ['id' => $board->id]);
    }

    public function test_cannot_manage_boards_in_a_workspace_the_user_is_unauthorized_for(): void
    {
        $admin = User::factory()->create(['is_admin' => false]);
        $workspace = $this->makeWorkspace();

        $response = $this->actingAs($admin)->getJson("/api/workspaces/{$workspace->id}/boards");

        $response->assertStatus(403);
    }
}
