<?php

namespace App\Domain\Vault;

use App\Domain\Links\WikilinkProjector;
use App\Domain\Vault\Store\VaultStore;
use App\Domain\Vault\Store\VaultStoreResolver;
use App\Models\Note;
use App\Models\Workspace;

/**
 * Bounded reconcile of the notes projection from the workspace's vault store (cron-safe).
 */
final class VaultReindexer
{
    public function __construct(
        private readonly VaultPathGuard $paths = new VaultPathGuard,
        private readonly NoteProjector $projector = new NoteProjector,
        private readonly WikilinkProjector $wikilinks = new WikilinkProjector,
        private readonly NoteRevisions $revisions = new NoteRevisions,
        private readonly ?VaultStoreResolver $stores = null,
    ) {}

    /**
     * @return array{scanned: int, upserted: int, removed: int}
     */
    public function reindex(Workspace $workspace, ?int $batchSize = null, ?string $actorId = null): array
    {
        $batchSize = max(1, $batchSize ?? (int) config('jotter.vault.reindex_batch_size', 50));
        $store = ($this->stores ?? app(VaultStoreResolver::class))->for($workspace);
        $store->makeDirectory('');

        $scanned = 0;
        $upserted = 0;
        $batch = [];

        foreach ($this->iterateMarkdownFiles($store, $batchSize) as $relative) {
            $batch[] = $relative;
            $scanned++;

            if (count($batch) >= $batchSize) {
                $upserted += $this->projectBatch($workspace, $store, $batch, $actorId);
                $batch = [];
            }
        }

        if ($batch !== []) {
            $upserted += $this->projectBatch($workspace, $store, $batch, $actorId);
        }

        $removed = $this->removeMissingNotes($workspace, $store, $batchSize);
        $this->wikilinks->resolveWorkspaceLinks($workspace);

        return [
            'scanned' => $scanned,
            'upserted' => $upserted,
            'removed' => $removed,
        ];
    }

    /**
     * @return \Generator<int, string>
     */
    private function iterateMarkdownFiles(VaultStore $store, int $pageSize): \Generator
    {
        $cursor = null;

        do {
            $page = $store->list('', $cursor, max($pageSize, 200));

            foreach ($page->entries as $entry) {
                $relative = $entry->path;
                if ($relative === '.trash' || str_starts_with($relative, '.trash/')) {
                    continue;
                }

                $name = strtolower(basename($relative));
                if (! str_ends_with($name, '.md')) {
                    continue;
                }

                // Excalidraw diagrams are stored as `*.excalidraw.md` (JSON payload,
                // not readable Markdown). Never index them as notes; leave in the vault.
                if (str_ends_with($name, '.excalidraw.md')) {
                    continue;
                }

                yield $relative;
            }

            $cursor = $page->nextCursor;
        } while ($cursor !== null);
    }

    /**
     * @param  list<string>  $batch
     */
    private function projectBatch(Workspace $workspace, VaultStore $store, array $batch, ?string $actorId): int
    {
        $count = 0;

        foreach ($batch as $relative) {
            try {
                $relative = $this->paths->normalize($workspace, $relative);
                $raw = $store->read($relative);
            } catch (\Throwable) {
                continue;
            }

            $document = MarkdownDocument::parse($raw, $this->fallbackTitle($relative));
            $note = $this->projector->project($workspace, $relative, $document);
            $this->revisions->recordRevision($note, $raw, $actorId);
            $count++;
        }

        return $count;
    }

    private function removeMissingNotes(Workspace $workspace, VaultStore $store, int $batchSize): int
    {
        $removed = 0;

        Note::query()
            ->where('workspace_id', $workspace->id)
            ->orderBy('id')
            ->chunkById($batchSize, function ($notes) use ($store, &$removed): void {
                foreach ($notes as $note) {
                    try {
                        $exists = $store->exists((string) $note->path);
                    } catch (\Throwable) {
                        $exists = false;
                    }

                    if (! $exists) {
                        $note->delete();
                        $removed++;
                    }
                }
            });

        return $removed;
    }
    private function fallbackTitle(string $relativePath): string
    {
        $base = basename(str_replace('\\', '/', $relativePath));
        if (str_ends_with(strtolower($base), '.md')) {
            $base = substr($base, 0, -3);
        }

        return $base === '' ? 'Untitled' : $base;
    }
}
