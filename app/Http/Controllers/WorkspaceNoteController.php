<?php

namespace App\Http\Controllers;

use App\Domain\Vault\Exceptions\PathTraversalRejected;
use App\Domain\Vault\Exceptions\VaultNoteNotFound;
use App\Domain\Vault\VaultStorage;
use App\Models\Note;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class WorkspaceNoteController extends Controller
{
    public function index(Workspace $workspace): JsonResponse
    {
        return response()->json([
            'data' => $workspace->notes()
                ->orderBy('path')
                ->get()
                ->map(fn (Note $note): array => $this->metadata($note))
                ->all(),
        ]);
    }

    public function store(Request $request, Workspace $workspace, VaultStorage $storage): JsonResponse
    {
        $validated = $request->validate([
            'path' => ['required', 'string', 'max:700'],
            'content' => ['present', 'string'],
        ]);

        try {
            $note = $storage->write($workspace, $validated['path'], $validated['content']);
        } catch (PathTraversalRejected $exception) {
            throw ValidationException::withMessages(['path' => [$exception->getMessage()]]);
        }

        return response()->json(['data' => $this->metadata($note)], 201);
    }

    public function show(Workspace $workspace, int $note, VaultStorage $storage): JsonResponse
    {
        $note = $this->scopedNote($workspace, $note);

        try {
            $content = $storage->readContents($workspace, $note->path);
        } catch (VaultNoteNotFound) {
            abort(404);
        }

        return response()->json([
            'data' => array_merge($this->metadata($note), [
                'content' => $content,
            ]),
        ]);
    }

    public function update(Request $request, Workspace $workspace, int $note, VaultStorage $storage): JsonResponse
    {
        $validated = $request->validate([
            'content' => ['present', 'string'],
        ]);
        $note = $this->scopedNote($workspace, $note);

        return response()->json([
            'data' => $this->metadata($storage->write($workspace, $note->path, $validated['content'])),
        ]);
    }

    public function destroy(Workspace $workspace, int $note, VaultStorage $storage): JsonResponse
    {
        $note = $this->scopedNote($workspace, $note);

        try {
            $storage->delete($workspace, $note->path);
        } catch (VaultNoteNotFound) {
            abort(404);
        }

        return response()->json(status: 204);
    }

    private function scopedNote(Workspace $workspace, int $noteId): Note
    {
        return $workspace->notes()->findOrFail($noteId);
    }

    /**
     * @return array{id: int, path: string, title: string, frontmatter: array<string, mixed>|null, updated_at: string}
     */
    private function metadata(Note $note): array
    {
        return [
            'id' => $note->id,
            'path' => $note->path,
            'title' => $note->title,
            'frontmatter' => $note->frontmatter,
            'updated_at' => $note->updated_at->toISOString(),
        ];
    }
}
