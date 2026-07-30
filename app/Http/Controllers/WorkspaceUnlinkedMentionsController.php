<?php

namespace App\Http\Controllers;

use App\Domain\Search\UnlinkedMentionsFinder;
use App\Models\Note;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;

final class WorkspaceUnlinkedMentionsController extends Controller
{
    public function index(Workspace $workspace, int $note, UnlinkedMentionsFinder $finder): JsonResponse
    {
        /** @var Note $target */
        $target = $workspace->notes()->findOrFail($note);

        return response()->json([
            'data' => $finder->find($workspace, $target),
        ]);
    }
}
