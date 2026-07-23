<?php

namespace App\Domain\Vault;

use App\Domain\Links\WikilinkProjector;
use App\Models\Note;
use App\Models\Workspace;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Bounded reconcile of the notes projection from on-disk Markdown (cron-safe).
 */
final class VaultReindexer
{
    public function __construct(
        private readonly VaultPathGuard $paths = new VaultPathGuard,
        private readonly NoteProjector $projector = new NoteProjector,
        private readonly WikilinkProjector $wikilinks = new WikilinkProjector,
    ) {}

    /**
     * @return array{scanned: int, upserted: int, removed: int}
     */
    public function reindex(Workspace $workspace, ?int $batchSize = null): array
    {
        $batchSize = max(1, $batchSize ?? (int) config('jotter.vault.reindex_batch_size', 50));
        $root = $this->paths->ensureVaultRoot($workspace);

        $scanned = 0;
        $upserted = 0;
        $batch = [];

        foreach ($this->iterateMarkdownFiles($root) as $file) {
            $absolute = $file->getPathname();
            $relative = $this->paths->toRelative($workspace, $absolute);
            $batch[] = [$absolute, $relative];
            $scanned++;

            if (count($batch) >= $batchSize) {
                $upserted += $this->projectBatch($workspace, $batch);
                $batch = [];
            }
        }

        if ($batch !== []) {
            $upserted += $this->projectBatch($workspace, $batch);
        }

        $removed = $this->removeMissingNotes($workspace, $root, $batchSize);
        $this->wikilinks->resolveWorkspaceLinks($workspace);

        return [
            'scanned' => $scanned,
            'upserted' => $upserted,
            'removed' => $removed,
        ];
    }

    /**
     * @return \Generator<int, SplFileInfo>
     */
    private function iterateMarkdownFiles(string $root): \Generator
    {
        if (! is_dir($root)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $root,
                FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO,
            ),
            RecursiveIteratorIterator::LEAVES_ONLY,
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $name = $file->getFilename();
            if (! str_ends_with(strtolower($name), '.md')) {
                continue;
            }

            // Skip symlink escapes: only yield files whose realpath stays inside root.
            $real = realpath($file->getPathname());
            if ($real === false) {
                continue;
            }

            $rootNormalized = rtrim(str_replace('\\', '/', $root), '/');
            $realNormalized = str_replace('\\', '/', $real);
            if (! str_starts_with($realNormalized, $rootNormalized.'/')) {
                continue;
            }

            yield $file;
        }
    }

    /**
     * @param  list<array{0: string, 1: string}>  $batch
     */
    private function projectBatch(Workspace $workspace, array $batch): int
    {
        $count = 0;

        foreach ($batch as [$absolute, $relative]) {
            $raw = file_get_contents($absolute);
            if ($raw === false) {
                continue;
            }

            $document = MarkdownDocument::parse($raw, $this->fallbackTitle($relative));
            $this->projector->project($workspace, $relative, $document);
            $count++;

        }

        return $count;
    }

    private function removeMissingNotes(Workspace $workspace, string $root, int $batchSize): int
    {
        $removed = 0;
        $rootNormalized = rtrim(str_replace('\\', '/', $root), '/');

        Note::query()
            ->where('workspace_id', $workspace->id)
            ->orderBy('id')
            ->chunkById($batchSize, function ($notes) use ($workspace, $rootNormalized, &$removed): void {
                foreach ($notes as $note) {
                    try {
                        $absolute = $this->paths->resolve($workspace, (string) $note->path, mustExist: false);
                    } catch (\Throwable) {
                        $note->delete();
                        $removed++;

                        continue;
                    }

                    $absoluteNormalized = str_replace('\\', '/', $absolute);
                    if (! is_file($absolute) || ! str_starts_with($absoluteNormalized, $rootNormalized.'/')) {
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
