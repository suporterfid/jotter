<?php

namespace App\Http\Controllers;

use App\Domain\Auth\Contracts\IdentityProvider;
use App\Models\Note;
use App\Models\NoteLink;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WorkspaceLinkReportController extends Controller
{
    public function __construct(
        private readonly IdentityProvider $identityProvider
    ) {}

    public function report(Request $request, int $workspaceId): JsonResponse
    {
        $subject = $this->identityProvider->resolveIdentity($request);
        if (! $subject) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (! $this->identityProvider->isAuthorizedForWorkspace($subject, $workspaceId)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $workspace = Workspace::query()->find($workspaceId);
        if (! $workspace) {
            return response()->json(['message' => 'Workspace not found.'], 404);
        }

        // 1. Broken Links: note_links with target_note_id IS NULL
        $workspaceNoteIds = Note::query()->where('workspace_id', $workspaceId)->pluck('id');

        $brokenLinkRows = NoteLink::query()
            ->whereIn('source_note_id', $workspaceNoteIds)
            ->whereNull('target_note_id')
            ->with(['sourceNote:id,path,title'])
            ->get();

        $groupedBroken = [];
        foreach ($brokenLinkRows as $link) {
            $ref = $link->target_ref;
            if (! isset($groupedBroken[$ref])) {
                $groupedBroken[$ref] = [
                    'target_ref' => $ref,
                    'count' => 0,
                    'sources' => [],
                ];
            }

            $groupedBroken[$ref]['count']++;
            if ($link->sourceNote) {
                $groupedBroken[$ref]['sources'][] = [
                    'id' => $link->sourceNote->id,
                    'path' => $link->sourceNote->path,
                    'title' => $link->sourceNote->title,
                ];
            }
        }

        // 2. Orphans: notes in workspace with no inbound resolved links
        $linkedTargetNoteIds = NoteLink::query()
            ->whereIn('source_note_id', $workspaceNoteIds)
            ->whereNotNull('target_note_id')
            ->pluck('target_note_id')
            ->unique();

        $orphans = Note::query()
            ->where('workspace_id', $workspaceId)
            ->whereNotIn('id', $linkedTargetNoteIds)
            ->get(['id', 'path', 'title']);

        return response()->json([
            'broken_links' => array_values($groupedBroken),
            'orphans' => $orphans,
        ]);
    }
}
