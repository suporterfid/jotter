<?php

namespace App\Http\Controllers;

use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Auth\Contracts\IdentityProvider;
use App\Domain\Auth\NoteAccess;
use App\Models\Note;
use App\Models\NoteChecklistItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class NoteChecklistItemController extends Controller
{
    public function __construct(
        private readonly IdentityProvider $identityProvider,
        private readonly NoteAccess $noteAccess,
        private readonly AuditRecorder $auditRecorder = new AuditRecorder
    ) {}

    public function index(Request $request, int $workspaceId, int $noteId): JsonResponse
    {
        $note = $this->authorizeAndFindNote($request, $workspaceId, $noteId);
        if ($note instanceof JsonResponse) {
            return $note;
        }

        $items = NoteChecklistItem::query()->where('note_id', $noteId)->orderBy('sort_position')->orderBy('id')->get();

        return response()->json(['data' => $items]);
    }

    public function store(Request $request, int $workspaceId, int $noteId): JsonResponse
    {
        $note = $this->authorizeAndFindNote($request, $workspaceId, $noteId);
        if ($note instanceof JsonResponse) {
            return $note;
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

        $this->recordActivity($request, $note, 'checklist_item_created', ['text' => $item->text]);

        return response()->json(['data' => $item], 201);
    }

    public function update(Request $request, int $workspaceId, int $noteId, int $itemId): JsonResponse
    {
        $note = $this->authorizeAndFindNote($request, $workspaceId, $noteId);
        if ($note instanceof JsonResponse) {
            return $note;
        }

        $item = NoteChecklistItem::query()->where('note_id', $noteId)->find($itemId);
        if (! $item) {
            return response()->json(['message' => __('messages.checklist_item_not_found')], 404);
        }

        $validated = $request->validate([
            'text' => ['sometimes', 'string', 'max:500'],
            'done' => ['sometimes', 'boolean'],
        ]);

        $item->update($validated);

        if (array_key_exists('done', $validated)) {
            $this->recordActivity($request, $note, $validated['done'] ? 'checklist_item_checked' : 'checklist_item_unchecked', ['text' => $item->text]);
        }

        return response()->json(['data' => $item]);
    }

    public function destroy(Request $request, int $workspaceId, int $noteId, int $itemId): JsonResponse
    {
        $note = $this->authorizeAndFindNote($request, $workspaceId, $noteId);
        if ($note instanceof JsonResponse) {
            return $note;
        }

        $item = NoteChecklistItem::query()->where('note_id', $noteId)->find($itemId);
        if (! $item) {
            return response()->json(['message' => __('messages.checklist_item_not_found')], 404);
        }

        $item->delete();

        return response()->json(null, 204);
    }

    private function authorizeAndFindNote(Request $request, int $workspaceId, int $noteId): Note|JsonResponse
    {
        $subject = $this->identityProvider->resolveIdentity($request);
        if (! $subject) {
            return response()->json(['message' => __('messages.unauthenticated')], 401);
        }

        if (! $this->identityProvider->isAuthorizedForWorkspace($subject, $workspaceId)) {
            return response()->json(['message' => __('messages.forbidden')], 403);
        }

        $note = Note::query()->where('workspace_id', $workspaceId)->where('id', $noteId)->first();
        if (! $note) {
            return response()->json(['message' => __('messages.note_not_found')], 404);
        }

        if ($request->isMethod('get')) {
            $this->noteAccess->assertView($subject, $note);
        } else {
            $this->noteAccess->assertEdit($subject, $note);
        }

        return $note;
    }

    private function recordActivity(Request $request, Note $note, string $action, array $metadata): void
    {
        $subject = $request->attributes->get('authenticated_subject');
        $this->auditRecorder->record(
            event: AuditEvent::NOTE_UPDATED,
            tenantId: $note->workspace->tenant_id,
            workspaceId: $note->workspace_id,
            actorId: $subject?->subjectId,
            metadata: array_merge(['action' => $action], $metadata),
            noteId: $note->id
        );
    }
}
