<?php

namespace App\Http\Controllers;

use App\Domain\Auth\AuthenticatedSubject;
use App\Domain\Auth\Contracts\IdentityProvider;
use App\Domain\Auth\NoteAccess;
use App\Models\FolderPosition;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class WorkspaceNoteTreeController extends Controller
{
    public function __construct(
        private readonly IdentityProvider $identityProvider,
        private readonly NoteAccess $noteAccess,
    ) {}

    public function index(Workspace $workspace): JsonResponse
    {
        return response()->json([
            'data' => FolderPosition::where('workspace_id', $workspace->id)
                ->get(['folder_path', 'sort_position'])
                ->map(fn (FolderPosition $p) => [
                    'folder_path' => $p->folder_path,
                    'sort_position' => $p->sort_position,
                ])
                ->all(),
        ]);
    }

    public function update(Request $request, Workspace $workspace): JsonResponse
    {
        $validated = $request->validate([
            'folder_path' => ['present', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.type' => ['required', 'in:note,folder'],
            'items.*.id' => ['required_if:items.*.type,note', 'integer'],
            'items.*.path' => ['required_if:items.*.type,folder', 'string'],
        ]);

        $folderPath = trim($validated['folder_path'], '/');
        $subject = $this->subject($request);
        if (! $subject) {
            return response()->json(['message' => __('messages.unauthenticated')], 401);
        }
        $children = $this->directChildren($workspace, $folderPath, $subject);

        $submittedNoteIds = collect($validated['items'])
            ->where('type', 'note')->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $submittedFolderPaths = collect($validated['items'])
            ->where('type', 'folder')->pluck('path')->sort()->values()->all();

        $expectedNoteIds = collect($children['note_ids'])->sort()->values()->all();
        $expectedFolderPaths = collect($children['folder_paths'])->sort()->values()->all();

        if ($submittedNoteIds !== $expectedNoteIds || $submittedFolderPaths !== $expectedFolderPaths) {
            throw ValidationException::withMessages([
                'items' => ['The submitted items do not match the current children of this folder.'],
            ]);
        }

        DB::transaction(function () use ($workspace, $validated) {
            foreach (array_values($validated['items']) as $index => $item) {
                $position = $index * 10;

                if ($item['type'] === 'note') {
                    $workspace->notes()->whereKey($item['id'])->update(['sort_position' => $position]);
                } else {
                    FolderPosition::updateOrCreate(
                        ['workspace_id' => $workspace->id, 'folder_path' => $item['path']],
                        ['sort_position' => $position],
                    );
                }
            }
        });

        return response()->json(status: 204);
    }

    /**
     * @return array{note_ids: array<int>, folder_paths: array<string>}
     */
    private function directChildren(Workspace $workspace, string $folderPath, AuthenticatedSubject $subject): array
    {
        $prefix = $folderPath === '' ? '' : $folderPath.'/';
        $noteIds = [];
        $folderPaths = [];

        foreach ($this->visibleNotes($workspace, $subject)->get(['id', 'path']) as $note) {
            if ($prefix !== '' && ! str_starts_with($note->path, $prefix)) {
                continue;
            }

            $remainder = substr($note->path, strlen($prefix));
            $slash = strpos($remainder, '/');

            if ($slash === false) {
                $noteIds[] = $note->id;
            } else {
                $folderPaths[$prefix.substr($remainder, 0, $slash)] = true;
            }
        }

        return ['note_ids' => $noteIds, 'folder_paths' => array_keys($folderPaths)];
    }

    private function visibleNotes(Workspace $workspace, AuthenticatedSubject $subject)
    {
        return $this->noteAccess->scopeVisible($workspace->notes()->getQuery(), $subject, $workspace->id);
    }

    private function subject(Request $request): ?AuthenticatedSubject
    {
        return $request->attributes->get('authenticated_subject')
            ?? $this->identityProvider->resolveIdentity($request);
    }
}
