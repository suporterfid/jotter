<?php

namespace App\Http\Controllers;

use App\Domain\Auth\Contracts\IdentityProvider;
use App\Domain\Auth\NoteAccess;
use App\Domain\Vault\NoteRevisions;
use App\Domain\Vault\VaultStorage;
use App\Models\Note;
use App\Models\NoteRevision;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WorkspaceNoteRevisionController extends Controller
{
    public function __construct(
        private readonly IdentityProvider $identityProvider,
        private readonly NoteRevisions $noteRevisions,
        private readonly VaultStorage $vaultStorage,
        private readonly NoteAccess $noteAccess,
    ) {}

    public function index(Request $request, int $workspaceId, int $noteId): JsonResponse
    {
        $subject = $this->identityProvider->resolveIdentity($request);
        if (! $subject) {
            return response()->json(['message' => __('messages.unauthenticated')], 401);
        }

        if (! $this->identityProvider->isAuthorizedForWorkspace($subject, $workspaceId)) {
            return response()->json(['message' => __('messages.forbidden')], 403);
        }

        $note = Note::query()
            ->where('workspace_id', $workspaceId)
            ->where('id', $noteId)
            ->first();

        if (! $note) {
            return response()->json(['message' => __('messages.note_not_found')], 404);
        }
        $this->noteAccess->assertView($subject, $note);

        $revisions = NoteRevision::query()
            ->where('workspace_id', $workspaceId)
            ->where('note_id', $noteId)
            ->orderByDesc('id')
            ->get(['id', 'note_id', 'content_hash', 'actor_id', 'created_at']);

        return response()->json(['data' => $revisions]);
    }

    public function show(Request $request, int $workspaceId, int $noteId, int $revisionId): JsonResponse
    {
        $subject = $this->identityProvider->resolveIdentity($request);
        if (! $subject) {
            return response()->json(['message' => __('messages.unauthenticated')], 401);
        }

        if (! $this->identityProvider->isAuthorizedForWorkspace($subject, $workspaceId)) {
            return response()->json(['message' => __('messages.forbidden')], 403);
        }

        $note = Note::query()->where('workspace_id', $workspaceId)->whereKey($noteId)->first();
        if (! $note) {
            return response()->json(['message' => __('messages.note_not_found')], 404);
        }
        $this->noteAccess->assertView($subject, $note);

        $revision = NoteRevision::query()
            ->where('workspace_id', $workspaceId)
            ->where('note_id', $noteId)
            ->where('id', $revisionId)
            ->first();

        if (! $revision) {
            return response()->json(['message' => __('messages.revision_not_found')], 404);
        }

        return response()->json(['data' => $revision]);
    }

    public function restore(Request $request, int $workspaceId, int $noteId, int $revisionId): JsonResponse
    {
        $subject = $this->identityProvider->resolveIdentity($request);
        if (! $subject) {
            return response()->json(['message' => __('messages.unauthenticated')], 401);
        }

        if (! $this->identityProvider->isAuthorizedForWorkspace($subject, $workspaceId)) {
            return response()->json(['message' => __('messages.forbidden')], 403);
        }

        $workspace = Workspace::query()->find($workspaceId);
        $note = Note::query()->where('workspace_id', $workspaceId)->where('id', $noteId)->first();
        $revision = NoteRevision::query()->where('workspace_id', $workspaceId)->where('note_id', $noteId)->where('id', $revisionId)->first();

        if (! $workspace || ! $note || ! $revision) {
            return response()->json(['message' => __('messages.resource_not_found')], 404);
        }
        $this->noteAccess->assertEdit($subject, $note);

        $restoredNote = $this->noteRevisions->restoreRevision(
            $workspace,
            $note,
            $revision,
            $this->vaultStorage,
            $subject->subjectId
        );

        return response()->json([
            'message' => __('messages.revision_restored_successfully'),
            'data' => $restoredNote,
        ]);
    }
}
