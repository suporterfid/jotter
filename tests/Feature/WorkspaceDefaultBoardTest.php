<?php

namespace Tests\Feature;

use App\Models\Board;
use App\Models\Tenant;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class WorkspaceDefaultBoardTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_workspace_automatically_creates_a_default_board(): void
    {
        $tenant = Tenant::create(['slug' => 'default-board-test', 'name' => 'Default Board Test']);
        $vaultPath = storage_path('app/vaults/default_board_test_'.uniqid());
        mkdir($vaultPath, 0755, true);

        $workspace = Workspace::create([
            'tenant_id' => $tenant->id,
            'slug' => 'default-board',
            'name' => 'Default Board Workspace',
            'vault_path' => $vaultPath,
        ]);

        $boards = Board::query()->where('workspace_id', $workspace->id)->get();

        $this->assertCount(1, $boards);
        $this->assertSame('Board', $boards->first()->name);
    }
}
