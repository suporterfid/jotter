<?php

namespace Tests\Feature;

use App\Models\Board;
use App\Models\Tenant;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BackfillDefaultBoardsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_a_default_board_only_for_workspaces_that_have_none(): void
    {
        $tenant = Tenant::create(['slug' => 'backfill-test', 'name' => 'Backfill Test']);

        $vaultPathA = storage_path('app/vaults/backfill_a_'.uniqid());
        mkdir($vaultPathA, 0755, true);
        $withoutBoard = Workspace::create(['tenant_id' => $tenant->id, 'slug' => 'no-board', 'name' => 'No Board', 'vault_path' => $vaultPathA]);
        // The auto-create-on-workspace-creation observer already gave this
        // one a board; delete it to simulate a workspace that predates that
        // behavior (e.g. the existing production workspace).
        Board::query()->where('workspace_id', $withoutBoard->id)->delete();

        $vaultPathB = storage_path('app/vaults/backfill_b_'.uniqid());
        mkdir($vaultPathB, 0755, true);
        $withBoard = Workspace::create(['tenant_id' => $tenant->id, 'slug' => 'has-board', 'name' => 'Has Board', 'vault_path' => $vaultPathB]);
        Board::query()->where('workspace_id', $withBoard->id)->update(['name' => 'Existing Custom Board']);

        $this->artisan('boards:backfill-default')->assertSuccessful();

        $this->assertSame(1, Board::query()->where('workspace_id', $withoutBoard->id)->count());
        $this->assertSame('Board', Board::query()->where('workspace_id', $withoutBoard->id)->first()->name);

        $this->assertSame(1, Board::query()->where('workspace_id', $withBoard->id)->count());
        $this->assertSame('Existing Custom Board', Board::query()->where('workspace_id', $withBoard->id)->first()->name);
    }
}
