<?php

namespace App\Observers;

use App\Models\Board;
use App\Models\Workspace;

class WorkspaceObserver
{
    public function created(Workspace $workspace): void
    {
        Board::create([
            'workspace_id' => $workspace->id,
            'name' => 'Board',
            'sort_position' => 0,
        ]);
    }
}
