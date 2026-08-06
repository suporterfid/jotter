<?php

namespace App\Http\Controllers;

use App\Domain\Auth\Contracts\IdentityProvider;
use App\Domain\Events\WorkspaceEventEmitter;
use App\Models\Note;
use App\Models\NoteComment;
use App\Models\User;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WorkspaceCommentController extends Controller
{
    public function __construct(
        private readonly IdentityProvider $identityProvider,
        private readonly WorkspaceEventEmitter $eventEmitter
    ) {}

    public function index(Request $request, int $workspaceId, int $noteId): JsonResponse
    {
        $subject = $this->identityProvider->resolveIdentity($request);
        if (! $subject) {
            return response()->json(['message' => __('messages.unauthenticated')], 401);
        }

        if (! $this->identityProvider->isAuthorizedForWorkspace($subject, $workspaceId)) {
            return response()->json(['message' => __('messages.forbidden')], 403);
        }

        $note = Note::query()->where('workspace_id', $workspaceId)->where('id', $noteId)->first();
        if (! $note) {
            return response()->json(['message' => __('messages.note_not_found')], 404);
        }

        $comments = NoteComment::query()
            ->where('workspace_id', $workspaceId)
            ->where('note_id', $noteId)
            ->orderBy('id')
            ->get();

        return response()->json(['data' => $comments]);
    }

    public function store(Request $request, int $workspaceId, int $noteId): JsonResponse
    {
        $subject = $this->identityProvider->resolveIdentity($request);
        if (! $subject) {
            return response()->json(['message' => __('messages.unauthenticated')], 401);
        }

        if (! $this->identityProvider->isAuthorizedForWorkspace($subject, $workspaceId)) {
            return response()->json(['message' => __('messages.forbidden')], 403);
        }

        $note = Note::query()->where('workspace_id', $workspaceId)->where('id', $noteId)->first();
        if (! $note) {
            return response()->json(['message' => __('messages.note_not_found')], 404);
        }

        $request->validate([
            'content' => ['required', 'string', 'max:2000'],
            'anchor_line' => ['nullable', 'integer', 'min:1'],
        ]);

        $content = $request->string('content')->trim()->toString();
        $anchorLine = $request->input('anchor_line');

        $comment = NoteComment::query()->create([
            'workspace_id' => $workspaceId,
            'note_id' => $noteId,
            'user_id' => $subject->user?->id,
            'actor_name' => $subject->name ?: 'Anonymous',
            'content' => $content,
            'anchor_line' => $anchorLine,
        ]);

        // Parse mentions (e.g. @username or @email)
        preg_match_all('/@([a-zA-Z0-9._-]+)/', $content, $matches);
        if (! empty($matches[1])) {
            foreach (array_unique($matches[1]) as $handle) {
                $targetUser = User::query()
                    ->where('email', $handle)
                    ->orWhere('name', 'LIKE', "%{$handle}%")
                    ->first();

                if ($targetUser) {
                    $this->eventEmitter->emitMention(
                        workspaceId: $workspaceId,
                        actorId: $subject->subjectId,
                        targetUserId: $targetUser->id,
                        noteTitle: $note->title,
                        commentContent: $content
                    );
                }
            }
        }

        return response()->json(['data' => $comment], 201);
    }

    public function destroy(Request $request, int $workspaceId, int $noteId, int $commentId): JsonResponse
    {
        $subject = $this->identityProvider->resolveIdentity($request);
        if (! $subject) {
            return response()->json(['message' => __('messages.unauthenticated')], 401);
        }

        if (! $this->identityProvider->isAuthorizedForWorkspace($subject, $workspaceId)) {
            return response()->json(['message' => __('messages.forbidden')], 403);
        }

        $comment = NoteComment::query()
            ->where('workspace_id', $workspaceId)
            ->where('note_id', $noteId)
            ->where('id', $commentId)
            ->first();

        if (! $comment) {
            return response()->json(['message' => __('messages.comment_not_found')], 404);
        }

        if ($comment->user_id !== $subject->user?->id && ! $subject->isAdmin) {
            return response()->json(['message' => __('messages.forbidden')], 403);
        }

        $comment->delete();

        return response()->json(['message' => __('messages.comment_deleted')]);
    }
}
