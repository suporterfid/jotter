<?php

namespace App\Http\Controllers;

use App\Domain\Auth\Contracts\IdentityProvider;
use App\Models\Note;
use App\Models\NoteProperty;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WorkspaceCollectionController extends Controller
{
    public function __construct(
        private readonly IdentityProvider $identityProvider
    ) {}

    public function index(Request $request, int $workspaceId): JsonResponse
    {
        $subject = $this->identityProvider->resolveIdentity($request);
        if (! $subject) {
            return response()->json(['message' => __('messages.unauthenticated')], 401);
        }

        if (! $this->identityProvider->isAuthorizedForWorkspace($subject, $workspaceId)) {
            return response()->json(['message' => __('messages.forbidden')], 403);
        }

        $propertyKey = $request->query('property');
        $propertyValue = $request->query('value');

        $query = Note::query()
            ->where('workspace_id', $workspaceId)
            ->with(['properties', 'tags'])
            ->withCount('comments')
            ->withCount('checklistItems as checklist_total')
            ->withCount(['checklistItems as checklist_done' => function ($cQuery) {
                $cQuery->where('done', true);
            }]);

        if ($propertyKey !== null && $propertyKey !== '') {
            $query->whereHas('properties', function ($pQuery) use ($propertyKey, $propertyValue) {
                $pQuery->where('name', $propertyKey);
                if ($propertyValue !== null && $propertyValue !== '') {
                    // A property's actual value lives in one of several typed
                    // columns (value_string/numeric/boolean/datetime) depending
                    // on its type — matching only value_string meant filtering
                    // could never find a match on any non-string property.
                    // Each alternate column is only compared when the filter
                    // value actually looks like that type, to avoid false
                    // positives from loose coercion (e.g. a non-numeric string
                    // matching value_numeric = 0, or any non-boolean string
                    // matching value_boolean = false).
                    $pQuery->where(function ($vQuery) use ($propertyValue) {
                        $vQuery->where('value_string', $propertyValue);

                        if (is_numeric($propertyValue)) {
                            $vQuery->orWhere('value_numeric', $propertyValue);
                        }

                        if (in_array(strtolower($propertyValue), ['true', 'false'], true)) {
                            $vQuery->orWhere('value_boolean', strtolower($propertyValue) === 'true');
                        }

                        if (strtotime($propertyValue) !== false) {
                            $vQuery->orWhere('value_datetime', $propertyValue);
                        }
                    });
                }
            });
        }

        if (! $request->boolean('include_archived')) {
            $query->whereDoesntHave('properties', function ($pQuery) {
                $pQuery->where('name', 'archived')->where('value_boolean', true);
            });
        }

        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));
        $notes = $query->orderBy('title')->paginate($perPage);

        return response()->json($notes);
    }
}
