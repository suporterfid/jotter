<?php

namespace App\Http\Controllers;

use App\Domain\Auth\Contracts\IdentityProvider;
use App\Domain\Auth\NoteAccess;
use App\Domain\Events\WorkspaceEventEmitter;
use App\Models\Note;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WorkspaceNoteWatchController extends Controller
{
    public function __construct(
        private readonly IdentityProvider $identityProvider,
        private readonly NoteAccess $noteAccess,
        private readonly WorkspaceEventEmitter $eventEmitter,
    ) {}

    public function update(Request $request, int $workspaceId, int $noteId): JsonResponse
    {
        $subject = $request->attributes->get('authenticated_subject')
            ?? $this->identityProvider->resolveIdentity($request);
        if (! $subject) {
            return response()->json(['message' => __('messages.unauthenticated')], 401);
        }
        if (! $this->identityProvider->isAuthorizedForWorkspace($subject, $workspaceId)) {
            return response()->json(['message' => __('messages.forbidden')], 403);
        }
        if (! $subject->user) {
            return response()->json(['message' => __('messages.forbidden')], 403);
        }

        $note = Note::query()->where('workspace_id', $workspaceId)->findOrFail($noteId);
        $this->noteAccess->assertView($subject, $note);
        $validated = $request->validate(['watching' => ['required', 'boolean']]);

        if ($validated['watching']) {
            $this->eventEmitter->watch($note, $subject->user->id);
        } else {
            $this->eventEmitter->unwatch($note, $subject->user->id);
        }

        return response()->json(['data' => ['watching' => (bool) $validated['watching']]]);
    }
}
