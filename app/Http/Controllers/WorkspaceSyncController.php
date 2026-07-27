<?php

namespace App\Http\Controllers;

use App\Domain\Auth\Contracts\IdentityProvider;
use App\Models\Note;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WorkspaceSyncController extends Controller
{
    public function __construct(
        private readonly IdentityProvider $identityProvider
    ) {}

    public function sync(Request $request, int $workspaceId): JsonResponse
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

        $since = $request->query('since');
        $query = Note::query()->where('workspace_id', $workspaceId);

        if ($since) {
            $query->where('updated_at', '>=', $since);
        }

        $notes = $query->get()->map(fn (Note $n) => [
            'id' => $n->id,
            'path' => $n->path,
            'title' => $n->title,
            'frontmatter' => $n->frontmatter,
            'updated_at' => $n->updated_at?->toIso8601String(),
        ]);

        return response()->json([
            'workspace' => [
                'id' => $workspace->id,
                'slug' => $workspace->slug,
                'name' => $workspace->name,
            ],
            'sync_timestamp' => now()->toIso8601String(),
            'notes' => $notes,
        ]);
    }
}
