<?php

namespace App\Http\Controllers;

use App\Domain\Search\SearchCriteria;
use App\Domain\Search\WorkspaceSearch;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WorkspaceSearchController extends Controller
{
    public function __invoke(Request $request, Workspace $workspace, WorkspaceSearch $search): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:100'],
            'title' => ['nullable', 'string', 'max:200'],
            'modified_after' => ['nullable', 'date'],
            'modified_before' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = isset($validated['q']) && trim($validated['q']) !== '' ? trim($validated['q']) : null;
        $title = isset($validated['title']) && trim($validated['title']) !== '' ? trim($validated['title']) : null;
        $tags = array_values(array_filter($validated['tags'] ?? [], fn ($t) => is_string($t) && trim($t) !== ''));
        $modifiedAfter = $validated['modified_after'] ?? null;
        $modifiedBefore = $validated['modified_before'] ?? null;
        $page = (int) ($validated['page'] ?? 1);

        $criteria = new SearchCriteria(
            query: $query,
            tags: $tags,
            title: $title,
            modifiedAfter: $modifiedAfter,
            modifiedBefore: $modifiedBefore,
            page: $page
        );

        if ($criteria->isEmpty()) {
            return response()->json([
                'message' => __('messages.search_criteria_required'),
                'errors' => [
                    'q' => ['At least one search filter or query string must be provided.'],
                ],
            ], 422);
        }

        return response()->json([
            'data' => $search->search($workspace, $criteria),
        ]);
    }
}
