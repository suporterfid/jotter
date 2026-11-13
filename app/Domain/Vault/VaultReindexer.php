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

        // S3 (#446): an object whose listed ETag equals the note's remote_version is
        // unchanged since Jotter last read or wrote it, so it is not downloaded again.
        $byVersion = $store->driver() === Store\VaultStoreDrivers::S3;

        $listed = [];
        foreach ($this->iterateMarkdownFiles($store, $batchSize) as $relative => $version) {
            $batch[$relative] = $version;
            $scanned++;
            if ($byVersion) {
                $listed[(string) $relative] = true;
            }

            if (count($batch) >= $batchSize) {
                $upserted += $this->projectBatch($workspace, $store, $batch, $actorId, $byVersion);
                $batch = [];
            }
        }

        if ($batch !== []) {
            $upserted += $this->projectBatch($workspace, $store, $batch, $actorId, $byVersion);
        }

        $removed = $this->removeMissingNotes($workspace, $store, $batchSize, $byVersion ? $listed : null);
        $this->wikilinks->resolveWorkspaceLinks($workspace);

        return [
            'scanned' => $scanned,
            'upserted' => $upserted,
            'removed' => $removed,
        ];
    }

    /**
     * @return \Generator<string, ?string> path => listed version
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

                yield $relative => $entry->version;
            }

            $cursor = $page->nextCursor;
        } while ($cursor !== null);
    }

    /**
     * @param  array<string, ?string>  $batch  path => listed version
     */
    private function projectBatch(Workspace $workspace, VaultStore $store, array $batch, ?string $actorId, bool $byVersion = false): int
    {
        $count = 0;
        $known = $byVersion
            ? Note::query()->where('workspace_id', $workspace->id)->whereIn('path', array_map('strval', array_keys($batch)))
                ->whereNotNull('remote_version')->pluck('remote_version', 'path')->all()
            : [];

        foreach ($batch as $relative => $version) {
            $relative = (string) $relative;
            if ($byVersion && $version !== null && ($known[$relative] ?? null) === $version) {
                continue;
            }

            try {
                $relative = $this->paths->normalize($workspace, $relative);
                $raw = $store->read($relative);
            } catch (\Throwable) {
                continue;
            }

            $document = MarkdownDocument::parse($raw, $this->fallbackTitle($relative));
            $note = $this->projector->project($workspace, $relative, $document);
            $this->revisions->recordRevision($note, $raw, $actorId);
            if ($byVersion && $version !== null) {
                Note::query()->whereKey($note->id)->toBase()->update(['remote_version' => $version]);
            }
            $count++;
        }

        return $count;
    }

    /**
     * @param  array<string, true>|null  $listed  paths the listing returned (S3: no HEAD for those)
     */
    private function removeMissingNotes(Workspace $workspace, VaultStore $store, int $batchSize, ?array $listed = null): int
    {
        $removed = 0;

        Note::query()
            ->where('workspace_id', $workspace->id)
            ->orderBy('id')
            ->chunkById($batchSize, function ($notes) use ($store, $listed, &$removed): void {
                foreach ($notes as $note) {
                    if ($listed !== null && isset($listed[(string) $note->path])) {
                        continue;
                    }
                    try {
                        $exists = $store->exists((string) $note->path);
                    } catch (Store\StoreUnavailable $unavailable) {
                        // A remote store that cannot answer proves nothing: never delete on an outage.
                        throw $unavailable;
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
