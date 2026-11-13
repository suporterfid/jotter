<?php

namespace App\Http\Controllers;

use App\Domain\Vault\Move\VaultMoveRefused;
use App\Domain\Vault\Move\VaultMover;
use App\Domain\Vault\Store\TenantStorageAccess;
use App\Models\TenantVaultStore;
use App\Models\VaultMove;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin side of vault:move (#437): start a move, follow its progress, roll it
 * back. The copy itself runs from the cron (`vault:process-moves`).
 */
final class AdminVaultMoveController extends Controller
{
    public function __construct(
        private readonly VaultMover $mover,
        private readonly TenantStorageAccess $access = new TenantStorageAccess,
    ) {}

    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        $this->access->authorize($request, $workspace->tenant);

        $moves = VaultMove::query()->where('workspace_id', $workspace->id)->latest('id')->limit(10)->get();

        return response()->json(['data' => [
            'workspace' => [
                'id' => $workspace->id,
                'vault_store_id' => $workspace->vault_store_id,
                'storage_state' => $workspace->storageState(),
            ],
            'moves' => $moves->map(fn (VaultMove $move): array => $move->toApiArray())->values(),
        ]]);
    }

    public function store(Request $request, Workspace $workspace): JsonResponse
    {
        $this->access->authorize($request, $workspace->tenant);

        $validated = $request->validate([
            'to_store_id' => ['present', 'nullable', 'integer', Rule::exists('vault_stores', 'id')->where('tenant_id', $workspace->tenant_id)],
        ]);
        $target = $validated['to_store_id'] === null ? null : TenantVaultStore::query()->findOrFail($validated['to_store_id']);

        try {
            $move = $this->mover->start($workspace, $target, $this->access->subject($request)?->subjectId);
        } catch (VaultMoveRefused $refused) {
            return response()->json(['message' => __('messages.vault_move_not_allowed', ['reason' => $refused->getMessage()])], 409);
        }

        return response()->json(['data' => $move->toApiArray()], 202);
    }

    public function rollback(Request $request, VaultMove $vaultMove): JsonResponse
    {
        $workspace = $vaultMove->workspace()->firstOrFail();
        $this->access->authorize($request, $workspace->tenant);

        try {
            $move = $this->mover->rollback($vaultMove, $this->access->subject($request)?->subjectId, $request->boolean('force'));
        } catch (VaultMoveRefused $refused) {
            return response()->json(['message' => __('messages.vault_move_not_allowed', ['reason' => $refused->getMessage()])], 409);
        }

        return response()->json(['message' => __('messages.vault_move_rolled_back'), 'data' => $move->toApiArray()]);
    }
}
