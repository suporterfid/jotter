<?php

namespace App\Http\Controllers;

use App\Domain\Vault\Exceptions\TrashRestoreConflict;
use App\Domain\Vault\NoteTrash;
use App\Models\Note;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;

final class WorkspaceTrashController extends Controller
{
    public function __construct(private readonly NoteTrash $trash) {}

    public function index(Workspace $workspace): JsonResponse
    {
        $data = $workspace->notes()
            ->onlyTrashed()
            ->orderByDesc('deleted_at')
            ->get()
            ->map(fn (Note $note): array => $this->trashMetadata($note))
            ->all();

        return response()->json(['data' => $data]);
    }

    public function restore(Workspace $workspace, int $note): JsonResponse
    {
        $note = $this->trashedNote($workspace, $note);

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

    public function destroy(Workspace $workspace, int $note): JsonResponse
    {
        $note = $this->trashedNote($workspace, $note);
        $this->trash->permanentlyDelete($workspace, $note);

        return response()->json(status: 204);
    }

    private function trashedNote(Workspace $workspace, int $noteId): Note
    {
        return Note::onlyTrashed()
            ->where('workspace_id', $workspace->id)
            ->findOrFail($noteId);
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
