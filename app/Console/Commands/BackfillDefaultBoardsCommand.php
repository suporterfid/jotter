<?php

namespace App\Console\Commands;

use App\Models\Board;
use App\Models\Workspace;
use Illuminate\Console\Command;

class BackfillDefaultBoardsCommand extends Command
{
    protected $signature = 'boards:backfill-default';

    protected $description = 'Create a default board for any workspace that has none (idempotent — for workspaces that predate the auto-create-on-workspace-creation behavior)';

    public function handle(): int
    {
        $workspaces = Workspace::query()->whereDoesntHave('boards')->get();

        foreach ($workspaces as $workspace) {
            Board::create([
                'workspace_id' => $workspace->id,
                'name' => 'Board',
                'sort_position' => 0,
            ]);
            $this->info("Created default board for workspace #{$workspace->id} ({$workspace->name})");
        }

        if ($workspaces->isEmpty()) {
            $this->info('Every workspace already has a board — nothing to do.');
        }

        return self::SUCCESS;
    }
}
