<?php

namespace App\Domain\Sharing;

use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Auth\AuthenticatedSubject;
use App\Domain\Auth\NoteAccess;
use App\Models\Note;
use App\Models\NoteShare;
use DateTimeInterface;

final class NoteShareService
{
    public function __construct(
        private readonly NoteAccess $noteAccess,
        private readonly AuditRecorder $auditRecorder,
    ) {}

    /**
     * @return array{share: NoteShare, token: string}
     */
    public function create(Note $note, AuthenticatedSubject $subject, ?DateTimeInterface $expiresAt): array
    {
        $this->assertCanManage($note, $subject);
        $note->loadMissing('workspace');

        $token = bin2hex(random_bytes(32));
        $share = NoteShare::query()->create([
            'note_id' => $note->id,
            'created_by_user_id' => $subject->user?->id,
            'token_hash' => NoteShare::hashToken($token),
            'expires_at' => $expiresAt,
        ]);

        $this->auditRecorder->record(
            AuditEvent::NOTE_SHARE_CREATED,
            $note->workspace->tenant_id,
            $note->workspace_id,
            $subject->subjectId,
            [
                'share_id' => $share->id,
                'expires_at' => $share->expires_at?->toIso8601String(),
            ],
            $note->id,
        );

        return ['share' => $share, 'token' => $token];
    }

    public function activeForToken(string $token): ?NoteShare
    {
        $share = NoteShare::query()
            ->active()
            ->with(['note.workspace'])
            ->where('token_hash', NoteShare::hashToken($token))
            ->first();

        if ($share?->note === null) {
            return null;
        }

        return $share;
    }

    public function revoke(NoteShare $share, AuthenticatedSubject $subject): NoteShare
    {
        $this->assertCanManage($share->note, $subject);
        $share->forceFill(['revoked_at' => now()])->save();

        $share->loadMissing('note.workspace');
        $this->auditRecorder->record(
            AuditEvent::NOTE_SHARE_REVOKED,
            $share->note->workspace->tenant_id,
            $share->note->workspace_id,
            $subject->subjectId,
            [
                'share_id' => $share->id,
                'revoked_at' => $share->revoked_at?->toIso8601String(),
            ],
            $share->note_id,
        );

        return $share->fresh();
    }

    public function assertCanManage(Note $note, AuthenticatedSubject $subject): void
    {
        $this->noteAccess->assertEdit($subject, $note);
    }
}
