<?php

namespace App\Domain\Vault\Store;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A write reached a workspace whose remote store failed for good (credentials
 * revoked, App uninstalled, repository or branch gone, plan §5.6). Reads keep
 * working from the cache; pending edits stay in the journal. Rendered as 423.
 */
final class WorkspaceStorageReadOnly extends RuntimeException
{
    public function __construct(public readonly int $vaultStoreId, string $reason = '')
    {
        parent::__construct(trim(__('messages.workspace_storage_read_only').' '.$reason));
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'storage_state' => 'read_only'], 423);
    }
}
