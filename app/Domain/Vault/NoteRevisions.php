<?php

namespace App\Domain\Vault;

use App\Models\Note;
use App\Models\NoteRevision;
use App\Models\Workspace;
use InvalidArgumentException;

final class NoteRevisions
{
    /**
     * Records a revision snapshot if the content has changed (deduplicated by sha256 content_hash).
     */
    public function recordRevision(Note $note, string $content, ?string $actorId = null): ?NoteRevision
    {
        $contentHash = hash('sha256', $content);

        /** @var NoteRevision|null $latest */
        $latest = NoteRevision::query()
            ->where('note_id', $note->id)
            ->latest('id')
            ->first();

        if ($latest && $latest->content_hash === $contentHash) {
            return null; // Content unchanged, deduplicate
        }

        return NoteRevision::query()->create([
            'workspace_id' => $note->workspace_id,
            'note_id' => $note->id,
            'content_hash' => $contentHash,
            'content' => $content,
            'actor_id' => $actorId,
        ]);
    }

    /**
     * Restores a past revision by writing its content back through VaultStorage,
     * which in turn produces a new revision snapshot (non-destructive restore).
     */
    public function restoreRevision(Workspace $workspace, Note $note, NoteRevision $revision, VaultStorage $storage, ?string $actorId = null): Note
    {
        if ($revision->workspace_id !== $workspace->id || $revision->note_id !== $note->id) {
            throw new InvalidArgumentException("Revision {$revision->id} does not belong to note {$note->id} in workspace {$workspace->id}");
        }

        return $storage->write($workspace, $note->path, $revision->content, $actorId);
    }
}
