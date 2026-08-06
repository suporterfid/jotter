<?php

namespace App\Http\Controllers;

use App\Domain\Auth\Contracts\IdentityProvider;
use App\Models\Board;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class BoardController extends Controller
{
    public function __construct(
        private readonly IdentityProvider $identityProvider
    ) {}

    public function index(Request $request, int $workspaceId): JsonResponse
    {
        if ($denied = $this->authorize($request, $workspaceId)) {
            return $denied;
        }

        $boards = Board::query()
            ->where('workspace_id', $workspaceId)
            ->orderBy('sort_position')
            ->orderBy('id')
            ->get();

        return response()->json(['data' => $boards]);
    }

    public function store(Request $request, int $workspaceId): JsonResponse
    {
        if ($denied = $this->authorize($request, $workspaceId)) {
            return $denied;
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'group_property' => ['nullable', 'string', 'max:255'],
            'swimlane_property' => ['nullable', 'string', 'max:255'],
            'filter_property' => ['nullable', 'string', 'max:255'],
            'filter_value' => ['nullable', 'string', 'max:255'],
            'column_config' => ['nullable', 'array'],
        ]);

        $board = Board::create([
            'workspace_id' => $workspaceId,
            'name' => $validated['name'],
            'group_property' => $validated['group_property'] ?? null,
            'swimlane_property' => $validated['swimlane_property'] ?? null,
            'filter_property' => $validated['filter_property'] ?? null,
            'filter_value' => $validated['filter_value'] ?? null,
            'column_config' => $validated['column_config'] ?? null,
            'sort_position' => Board::query()->where('workspace_id', $workspaceId)->count(),
        ]);

        return response()->json(['data' => $board], 201);
    }

    public function update(Request $request, int $workspaceId, int $boardId): JsonResponse
    {
        if ($denied = $this->authorize($request, $workspaceId)) {
            return $denied;
        }

        $board = Board::query()->where('workspace_id', $workspaceId)->find($boardId);
        if (! $board) {
            return response()->json(['message' => __('messages.board_not_found')], 404);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'group_property' => ['sometimes', 'nullable', 'string', 'max:255'],
            'swimlane_property' => ['sometimes', 'nullable', 'string', 'max:255'],
            'filter_property' => ['sometimes', 'nullable', 'string', 'max:255'],
            'filter_value' => ['sometimes', 'nullable', 'string', 'max:255'],
            'column_config' => ['sometimes', 'nullable', 'array'],
        ]);

        $board->update($validated);

        return response()->json(['data' => $board]);
    }

    public function destroy(Request $request, int $workspaceId, int $boardId): JsonResponse
    {
        if ($denied = $this->authorize($request, $workspaceId)) {
            return $denied;
        }

        $board = Board::query()->where('workspace_id', $workspaceId)->find($boardId);
        if (! $board) {
            return response()->json(['message' => __('messages.board_not_found')], 404);
        }

        $board->delete();

        return response()->json(null, 204);
    }

    private function authorize(Request $request, int $workspaceId): ?JsonResponse
    {
        $subject = $this->identityProvider->resolveIdentity($request);
        if (! $subject) {
            return response()->json(['message' => __('messages.unauthenticated')], 401);
        }

        if (! $this->identityProvider->isAuthorizedForWorkspace($subject, $workspaceId)) {
            return response()->json(['message' => __('messages.forbidden')], 403);
        }

        return null;
    }
}
