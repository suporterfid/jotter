<?php

namespace App\Http\Controllers;

use App\Domain\Auth\Contracts\IdentityProvider;
use App\Models\Note;
use App\Models\NoteChecklistItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class NoteChecklistItemController extends Controller
{
    public function __construct(
        private readonly IdentityProvider $identityProvider
    ) {}

    public function index(Request $request, int $workspaceId, int $noteId): JsonResponse
    {
        if ($denied = $this->authorizeAndFindNote($request, $workspaceId, $noteId)) {
            return $denied;
        }

        $items = NoteChecklistItem::query()->where('note_id', $noteId)->orderBy('sort_position')->orderBy('id')->get();

        return response()->json(['data' => $items]);
    }

    public function store(Request $request, int $workspaceId, int $noteId): JsonResponse
    {
        if ($denied = $this->authorizeAndFindNote($request, $workspaceId, $noteId)) {
            return $denied;
        }

        $validated = $request->validate([
            'text' => ['required', 'string', 'max:500'],
        ]);

        $item = NoteChecklistItem::create([
            'note_id' => $noteId,
            'text' => $validated['text'],
            'done' => false,
            'sort_position' => NoteChecklistItem::query()->where('note_id', $noteId)->count(),
        ]);

        return response()->json(['data' => $item], 201);
    }

    public function update(Request $request, int $workspaceId, int $noteId, int $itemId): JsonResponse
    {
        if ($denied = $this->authorizeAndFindNote($request, $workspaceId, $noteId)) {
            return $denied;
        }

        $item = NoteChecklistItem::query()->where('note_id', $noteId)->find($itemId);
        if (! $item) {
            return response()->json(['message' => 'Checklist item not found.'], 404);
        }

        $validated = $request->validate([
            'text' => ['sometimes', 'string', 'max:500'],
            'done' => ['sometimes', 'boolean'],
        ]);

        $item->update($validated);

        return response()->json(['data' => $item]);
    }

    public function destroy(Request $request, int $workspaceId, int $noteId, int $itemId): JsonResponse
    {
        if ($denied = $this->authorizeAndFindNote($request, $workspaceId, $noteId)) {
            return $denied;
        }

        $item = NoteChecklistItem::query()->where('note_id', $noteId)->find($itemId);
        if (! $item) {
            return response()->json(['message' => 'Checklist item not found.'], 404);
        }

        $item->delete();

        return response()->json(null, 204);
    }

    private function authorizeAndFindNote(Request $request, int $workspaceId, int $noteId): ?JsonResponse
    {
        $subject = $this->identityProvider->resolveIdentity($request);
        if (! $subject) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (! $this->identityProvider->isAuthorizedForWorkspace($subject, $workspaceId)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $note = Note::query()->where('workspace_id', $workspaceId)->where('id', $noteId)->first();
        if (! $note) {
            return response()->json(['message' => 'Note not found.'], 404);
        }

        return null;
    }
}
