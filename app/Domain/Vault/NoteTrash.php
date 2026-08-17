<?php

namespace App\Domain\Vault;

use App\Domain\Vault\Exceptions\TrashRestoreConflict;
use App\Models\Note;
use App\Models\Workspace;
use Illuminate\Support\Str;

final class NoteTrash
{
    public function __construct(
        private readonly VaultStorage $storage,
        private readonly VaultPathGuard $paths = new VaultPathGuard,
    ) {}

    public function trash(Workspace $workspace, Note $note): Note
    {
        $this->assertWorkspace($workspace, $note);

        if ($note->trashed()) {
            return $note;
        }

        $originalPath = (string) $note->path;
        $trashPath = '.trash/'.((int) $note->id).'-'.Str::uuid()->toString().'.md';

        $this->storage->moveFile($workspace, $originalPath, $trashPath);

        try {
            $note->forceFill([
                'path' => $trashPath,
                'original_path' => $originalPath,
            ])->save();
            $note->delete();
        } catch (\Throwable $exception) {
            $this->storage->moveFile($workspace, $trashPath, $originalPath);
            throw $exception;
        }

        return $note->fresh(['workspace']);
    }

    public function restore(Workspace $workspace, Note $note): Note
    {
        $this->assertWorkspace($workspace, $note);

        if (! $note->trashed()) {
            return $note;
        }

        $originalPath = trim((string) $note->original_path);
        if ($originalPath === '') {
            throw new \RuntimeException("Trashed note {$note->id} has no original path.");
        }

        $occupied = Note::query()
            ->where('workspace_id', $workspace->id)
            ->where('path', $originalPath)
            ->whereKeyNot($note->id)
            ->exists();

        if ($occupied || $this->storage->exists($workspace, $originalPath)) {
            throw new TrashRestoreConflict($originalPath);
        }

        $trashPath = (string) $note->path;
        $this->storage->moveFile($workspace, $trashPath, $originalPath);

        $note->forceFill([
            'path' => $originalPath,
            'original_path' => null,
        ])->save();
        $note->restore();

        $contents = $this->storage->readContents($workspace, $originalPath);

        return $this->storage->write($workspace, $originalPath, $contents);
    }

    public function permanentlyDelete(Workspace $workspace, Note $note): void
    {
        $this->assertWorkspace($workspace, $note);

        if (! $note->trashed()) {
            throw new \InvalidArgumentException("Note {$note->id} is not in the trash.");
        }

        $absolute = $this->paths->resolve($workspace, (string) $note->path, mustExist: false);
        if (is_file($absolute) && ! unlink($absolute)) {
            throw new \RuntimeException("Unable to permanently delete trashed note [{$note->path}].");
        }

        $note->forceDelete();
    }

    public function purgeExpired(int $days, int $batchSize = 100): int
    {
        if ($days < 1 || $batchSize < 1) {
            throw new \InvalidArgumentException('Trash retention days and batch size must be positive.');
        }

        $expired = Note::onlyTrashed()
            ->with('workspace')
            ->where('deleted_at', '<', now()->subDays($days))
            ->orderBy('id')
            ->limit($batchSize)
            ->get();

        foreach ($expired as $note) {
            $this->permanentlyDelete($note->workspace, $note);
        }

        return $expired->count();
    }

    private function assertWorkspace(Workspace $workspace, Note $note): void
    {
        if ((int) $note->workspace_id !== (int) $workspace->id) {
            throw new \InvalidArgumentException("Note {$note->id} does not belong to workspace {$workspace->id}.");
        }
    }
}
