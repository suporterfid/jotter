<?php

namespace App\Http\Controllers;

use App\Domain\Auth\Contracts\IdentityProvider;
use App\Domain\Auth\NoteAccess;
use App\Domain\Vault\Exceptions\TrashRestoreConflict;
use App\Domain\Vault\NoteTrash;
use App\Models\Note;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WorkspaceTrashController extends Controller
{
    public function __construct(
        private readonly NoteTrash $trash,
        private readonly IdentityProvider $identityProvider,
        private readonly NoteAccess $noteAccess,
    ) {}

    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        $subject = $request->attributes->get('authenticated_subject')
            ?? $this->identityProvider->resolveIdentity($request);
        if (! $subject) {
            return response()->json(['message' => __('messages.unauthenticated')], 401);
        }
        $data = $this->noteAccess->scopeVisible($workspace->notes()->onlyTrashed()->getQuery(), $subject, $workspace->id)
            ->orderByDesc('deleted_at')
            ->get()
            ->map(fn (Note $note): array => $this->trashMetadata($note))
            ->all();

        return response()->json(['data' => $data]);
    }

    public function restore(Request $request, Workspace $workspace, int $note): JsonResponse
    {
        $note = $this->trashedNote($workspace, $note);
        $this->noteAccess->assertEdit($this->subject($request), $note);

        try {
            $restored = $this->trash->restore($workspace, $note);
        } catch (TrashRestoreConflict) {
            return response()->json([
                'message' => __('messages.trash_restore_conflict'),
            ], 409);
        }

        return response()->json([
            'data' => [
                'id' => $restored->id,
                'path' => $restored->path,
                'title' => $restored->title,
                'frontmatter' => $restored->frontmatter,
                'updated_at' => $restored->updated_at->toISOString(),
            ],
        ]);
    }

    public function destroy(Request $request, Workspace $workspace, int $note): JsonResponse
    {
        $note = $this->trashedNote($workspace, $note);
        $this->noteAccess->assertEdit($this->subject($request), $note);
        $this->trash->permanentlyDelete($workspace, $note);

        return response()->json(status: 204);
    }

    private function trashedNote(Workspace $workspace, int $noteId): Note
    {
        return Note::onlyTrashed()
            ->where('workspace_id', $workspace->id)
            ->findOrFail($noteId);
    }

    private function subject(Request $request): \App\Domain\Auth\AuthenticatedSubject
    {
        return $request->attributes->get('authenticated_subject')
            ?? $this->identityProvider->resolveIdentity($request);
    }

    /**
     * @return array{id: int, title: string, original_path: string|null, frontmatter: array<string, mixed>|null, deleted_at: string|null}
     */
    private function trashMetadata(Note $note): array
    {
        return [
            'id' => $note->id,
            'title' => $note->title,
            'original_path' => $note->original_path,
            'frontmatter' => $note->frontmatter,
            'deleted_at' => $note->deleted_at?->toISOString(),
        ];
    }
}
