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
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (! $this->identityProvider->isAuthorizedForWorkspace($subject, $workspaceId)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $propertyKey = $request->query('property');
        $propertyValue = $request->query('value');

        $query = Note::query()
            ->where('workspace_id', $workspaceId)
            ->with(['properties', 'tags']);

        if ($propertyKey !== null && $propertyKey !== '') {
            $query->whereHas('properties', function ($pQuery) use ($propertyKey, $propertyValue) {
                $pQuery->where('name', $propertyKey);
                if ($propertyValue !== null && $propertyValue !== '') {
                    $pQuery->where('value_string', $propertyValue);
                }
            });
        }

        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));
        $notes = $query->orderBy('title')->paginate($perPage);

        return response()->json($notes);
    }
}
