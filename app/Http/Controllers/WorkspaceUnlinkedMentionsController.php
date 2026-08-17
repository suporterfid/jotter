<?php

namespace App\Http\Controllers;

use App\Domain\Search\UnlinkedMentionsFinder;
use App\Models\Note;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WorkspaceUnlinkedMentionsController extends Controller
{
    public function index(Request $request, Workspace $workspace, int $note, UnlinkedMentionsFinder $finder): JsonResponse
    {
        /** @var Note $target */
        $target = $workspace->notes()->findOrFail($note);

        return response()->json([
            'data' => $finder->find($workspace, $target, $request->attributes->get('authenticated_subject')),
        ]);
    }
}
