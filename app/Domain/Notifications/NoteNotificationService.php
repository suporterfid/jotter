<?php

namespace App\Domain\Notifications;

use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Auth\AuthenticatedSubject;
use App\Domain\Auth\NoteAccess;
use App\Models\Note;
use App\Models\NoteComment;
use App\Models\NoteWatcher;
use App\Models\Notification;
use Illuminate\Support\Carbon;

final class NoteNotificationService
{
    public function __construct(
        private readonly AuditRecorder $auditRecorder = new AuditRecorder,
        private readonly NoteAccess $noteAccess = new NoteAccess,
    ) {}

    public function watch(Note $note, int $userId): NoteWatcher
    {
        return NoteWatcher::query()->updateOrCreate(
            ['note_id' => $note->id, 'user_id' => $userId],
            ['workspace_id' => $note->workspace_id, 'is_watching' => true],
        );
    }

    public function unwatch(Note $note, int $userId): NoteWatcher
    {
        $watcher = NoteWatcher::query()->firstOrCreate(
            ['note_id' => $note->id, 'user_id' => $userId],
            ['workspace_id' => $note->workspace_id, 'is_watching' => false],
        );

        if ($watcher->is_watching) {
            $watcher->update(['is_watching' => false]);
        }

        return $watcher->refresh();
    }

    public function isWatching(Note $note, int $userId): bool
    {
        return (bool) NoteWatcher::query()
            ->where('note_id', $note->id)
            ->where('user_id', $userId)
            ->where('is_watching', true)
            ->value('is_watching');
    }

    public function ensureAutoWatch(Note $note, ?int $actorUserId): void
    {
        if ($actorUserId === null) {
            return;
        }

        NoteWatcher::query()->firstOrCreate(
            ['note_id' => $note->id, 'user_id' => $actorUserId],
            ['workspace_id' => $note->workspace_id, 'is_watching' => true],
        );
    }

    public function transferWatchers(Note $from, Note $to): void
    {
        foreach (NoteWatcher::query()->where('note_id', $from->id)->get() as $watcher) {
            $existing = NoteWatcher::query()
                ->where('note_id', $to->id)
                ->where('user_id', $watcher->user_id)
                ->first();
            if ($existing) {
                $existing->update(['is_watching' => $watcher->is_watching]);
                $watcher->delete();
                continue;
            }

            $watcher->update(['note_id' => $to->id, 'workspace_id' => $to->workspace_id]);
        }
    }

    public function emitNoteEdited(Note $note, AuthenticatedSubject $actor): void
    {
        $this->emitToWatchers(
            note: $note,
            type: NotificationType::NOTE_EDITED,
            actor: $actor,
            title: "'{$note->title}' was edited",
            data: $this->noteData($note, $actor),
            event: AuditEvent::NOTE_UPDATED,
            action: 'note_edited',
        );
    }

    public function emitNoteMoved(Note $note, AuthenticatedSubject $actor, string $oldPath): void
    {
        $this->emitToWatchers(
            note: $note,
            type: NotificationType::NOTE_MOVED,
            actor: $actor,
            title: "'{$note->title}' was moved",
            data: $this->noteData($note, $actor) + ['old_path' => $oldPath],
            event: AuditEvent::NOTE_MOVED,
            action: 'note_moved',
        );
    }

    public function emitNoteDeleted(Note $note, AuthenticatedSubject $actor): void
    {
        $this->emitToWatchers(
            note: $note,
            type: NotificationType::NOTE_DELETED,
            actor: $actor,
            title: "'{$note->title}' was deleted",
            data: $this->noteData($note, $actor) + ['target_kind' => 'trash'],
            event: AuditEvent::NOTE_DELETED,
            action: 'note_deleted',
            coalesce: false,
        );
    }

    public function emitCommentAdded(Note $note, NoteComment $comment, AuthenticatedSubject $actor): void
    {
        $this->emitToWatchers(
            note: $note,
            type: NotificationType::NOTE_COMMENTED,
            actor: $actor,
            title: "New comment on '{$note->title}'",
            data: $this->noteData($note, $actor) + [
                'comment_id' => $comment->id,
                'comment_snippet' => mb_substr($comment->content, 0, 100),
            ],
            event: AuditEvent::NOTE_UPDATED,
            action: 'note_commented',
            coalesce: false,
        );
    }

    public function emitCommentReply(Note $note, NoteComment $comment, NoteComment $parent, AuthenticatedSubject $actor): void
    {
        $this->emitToWatchers(
            note: $note,
            type: NotificationType::COMMENT_REPLY,
            actor: $actor,
            title: "New reply in '{$note->title}'",
            data: $this->noteData($note, $actor) + [
                'comment_id' => $comment->id,
                'parent_comment_id' => $parent->id,
                'comment_snippet' => mb_substr($comment->content, 0, 100),
            ],
            event: AuditEvent::NOTE_UPDATED,
            action: 'comment_replied',
            coalesce: false,
        );

        $participantIds = NoteComment::query()
            ->where('note_id', $note->id)
            ->where(function ($query) use ($parent): void {
                $query->where('id', $parent->id)->orWhere('parent_comment_id', $parent->id);
            })
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->unique()
            ->reject(fn ($id): bool => (int) $id === $actor->user?->id);

        foreach ($participantIds as $userId) {
            if (NoteWatcher::query()
                ->where('note_id', $note->id)
                ->where('user_id', $userId)
                ->where('is_watching', true)
                ->exists()) {
                continue;
            }

            $this->createForUser(
                note: $note,
                userId: (int) $userId,
                actor: $actor,
                type: NotificationType::COMMENT_REPLY,
                title: "New reply in '{$note->title}'",
                data: $this->noteData($note, $actor) + [
                    'comment_id' => $comment->id,
                    'parent_comment_id' => $parent->id,
                    'comment_snippet' => mb_substr($comment->content, 0, 100),
                ],
                coalesce: false,
            );
        }
    }

    /** @return array<string, mixed> */
    private function noteData(Note $note, AuthenticatedSubject $actor): array
    {
        return [
            'actor_id' => $actor->subjectId,
            'actor_name' => $actor->name,
            'note_id' => $note->id,
            'note_path' => $note->path,
            'note_title' => $note->title,
            'target_kind' => 'note',
        ];
    }

    /** @param array<string, mixed> $data */
    private function emitToWatchers(
        Note $note,
        NotificationType $type,
        AuthenticatedSubject $actor,
        string $title,
        array $data,
        AuditEvent $event,
        string $action,
        bool $coalesce = true,
    ): void {
        $watchers = NoteWatcher::query()
            ->where('workspace_id', $note->workspace_id)
            ->where('note_id', $note->id)
            ->where('is_watching', true)
            ->with('user')
            ->get();

        $recipientCount = 0;
        foreach ($watchers as $watcher) {
            if ($watcher->user === null || $watcher->user_id === $actor->user?->id) {
                continue;
            }

            $recipient = new AuthenticatedSubject(
                subjectId: (string) $watcher->user->id,
                email: $watcher->user->email,
                name: $watcher->user->name,
                user: $watcher->user,
            );
            if (! $this->noteAccess->canView($recipient, $note)) {
                continue;
            }

            $this->createForUser($note, $watcher->user_id, $actor, $type, $title, $data, $coalesce);
            $recipientCount++;
        }

        $this->auditRecorder->record(
            event: $event,
            workspaceId: $note->workspace_id,
            actorId: $actor->subjectId,
            noteId: $note->id,
            metadata: [
                'action' => $action,
                'notification_type' => $type->value,
                'recipient_count' => $recipientCount,
            ],
        );
    }

    /** @param array<string, mixed> $data */
    private function createForUser(
        Note $note,
        int $userId,
        AuthenticatedSubject $actor,
        NotificationType $type,
        string $title,
        array $data,
        bool $coalesce = true,
    ): void {
        $dedupeKey = $coalesce ? implode(':', [$type->value, $note->workspace_id, $note->id, $userId]) : null;
        $notification = $dedupeKey === null ? null : Notification::query()
            ->where('workspace_id', $note->workspace_id)
            ->where('user_id', $userId)
            ->where('type', $type->value)
            ->where('dedupe_key', $dedupeKey)
            ->where('created_at', '>=', Carbon::now()->subMinute())
            ->latest('id')
            ->first();

        $payload = $data + ['actor_id' => $actor->subjectId];
        if ($notification) {
            $notification->update(['title' => $title, 'data' => $payload]);
            return;
        }

        Notification::query()->create([
            'workspace_id' => $note->workspace_id,
            'user_id' => $userId,
            'type' => $type->value,
            'title' => $title,
            'data' => $payload,
            'dedupe_key' => $dedupeKey,
        ]);
    }
}
