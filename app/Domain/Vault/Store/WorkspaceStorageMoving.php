<?php

namespace App\Domain\Vault\Store;

use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A write reached a workspace whose vault is being moved to another store
 * (D9). Rendered as 423 Locked, which WebDAV clients understand; reads keep
 * working from the source until the swap.
 */
final class WorkspaceStorageMoving extends RuntimeException
{
    public function __construct(public readonly int $workspaceId)
    {
        parent::__construct(__('messages.workspace_storage_moving'));
    }

    /**
     * Throws when the workspace is moving. Reads the state from the database,
     * not from the (possibly stale) model, because the move starts in another process.
     *
     * @throws self
     */
    public static function assertWritable(Workspace $workspace): void
    {
        if ($workspace->id === null) {
            return;
        }

        $state = Workspace::query()->whereKey($workspace->id)->value('storage_state');
        if ($state === 'moving') {
            throw new self((int) $workspace->id);
        }
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'storage_state' => 'moving'], 423);
    }
}
