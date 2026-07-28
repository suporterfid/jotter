<?php

namespace App\Domain\Events;

use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditRecorder;
use App\Models\Notification;

final class WorkspaceEventEmitter
{
    public function __construct(
        private readonly AuditRecorder $auditRecorder = new AuditRecorder
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
}
