<?php

namespace App\Http\Controllers;

use App\Domain\Search\WorkspaceSearch;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WorkspaceSearchController extends Controller
{
    public function __invoke(Request $request, Workspace $workspace, WorkspaceSearch $search): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'max:200', 'not_regex:/^\s*$/u'],
        ]);

        return response()->json([
            'data' => $search->search($workspace, trim($validated['q'])),
        ]);
    }
}
