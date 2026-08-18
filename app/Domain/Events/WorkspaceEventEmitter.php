<?php

namespace App\Domain\Events;

use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Auth\AuthenticatedSubject;
use App\Domain\Notifications\NoteNotificationService;
use App\Models\Note;
use App\Models\NoteComment;
use App\Models\Notification;

final class WorkspaceEventEmitter
{
    public function __construct(
        private readonly AuditRecorder $auditRecorder = new AuditRecorder,
        private readonly NoteNotificationService $notificationService = new NoteNotificationService,
    ) {}

    public function emitMention(int $workspaceId, string $actorId, int $targetUserId, string $noteTitle, string $commentContent): void
    {
        // 1. Audit log entry (append-only)
        $this->auditRecorder->record(
            event: AuditEvent::NOTE_UPDATED,
            workspaceId: $workspaceId,
            actorId: $actorId,
            metadata: ['action' => 'user_mentioned', 'target_user_id' => $targetUserId, 'note_title' => $noteTitle]
        );

        // 2. User notification
        Notification::query()->create([
            'workspace_id' => $workspaceId,
            'user_id' => $targetUserId,
            'type' => 'mention',
            'title' => "You were mentioned in '{$noteTitle}'",
            'data' => [
                'actor_id' => $actorId,
                'comment_snippet' => mb_substr($commentContent, 0, 100),
            ],
        ]);
    }

    public function watch(Note $note, int $userId): void
    {
        $this->notificationService->watch($note, $userId);
    }

    public function unwatch(Note $note, int $userId): void
    {
        $this->notificationService->unwatch($note, $userId);
    }

    public function isWatching(Note $note, int $userId): bool
    {
        return $this->notificationService->isWatching($note, $userId);
    }

    public function ensureAutoWatch(Note $note, ?int $actorUserId): void
    {
        $this->notificationService->ensureAutoWatch($note, $actorUserId);
    }

    public function transferWatchers(Note $from, Note $to): void
    {
        $this->notificationService->transferWatchers($from, $to);
    }

    public function emitNoteEdited(Note $note, AuthenticatedSubject $actor): void
    {
        $this->notificationService->emitNoteEdited($note, $actor);
    }

    public function emitNoteMoved(Note $note, AuthenticatedSubject $actor, string $oldPath): void
    {
        $this->notificationService->emitNoteMoved($note, $actor, $oldPath);
    }

    public function emitNoteDeleted(Note $note, AuthenticatedSubject $actor): void
    {
        $this->notificationService->emitNoteDeleted($note, $actor);
    }

    public function emitCommentAdded(Note $note, NoteComment $comment, AuthenticatedSubject $actor): void
    {
        $this->notificationService->emitCommentAdded($note, $comment, $actor);
    }

    public function emitCommentReply(Note $note, NoteComment $comment, NoteComment $parent, AuthenticatedSubject $actor): void
    {
        $this->notificationService->emitCommentReply($note, $comment, $parent, $actor);
    }
}
