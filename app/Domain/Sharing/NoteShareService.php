<?php

namespace App\Domain\Sharing;

use App\Domain\Auth\AuthenticatedSubject;
use App\Domain\Auth\NoteAccess;
use App\Models\Note;
use App\Models\NoteShare;
use DateTimeInterface;

final class NoteShareService
{
    public function __construct(
        private readonly NoteAccess $noteAccess,
    ) {}

    /**
     * @return array{share: NoteShare, token: string}
     */
    public function create(Note $note, AuthenticatedSubject $subject, ?DateTimeInterface $expiresAt): array
    {
        $this->assertCanManage($note, $subject);

        $token = bin2hex(random_bytes(32));
        $share = NoteShare::query()->create([
            'note_id' => $note->id,
            'created_by_user_id' => $subject->user?->id,
            'token_hash' => NoteShare::hashToken($token),
            'expires_at' => $expiresAt,
        ]);

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

        return $share->fresh();
    }

    public function assertCanManage(Note $note, AuthenticatedSubject $subject): void
    {
        $this->noteAccess->assertEdit($subject, $note);
    }
}
