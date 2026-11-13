<?php

namespace App\Domain\Vault\Store\GitHub;

use App\Domain\Vault\Exceptions\VaultNoteNotFound;
use App\Domain\Vault\Store\StoreCapabilities;
use App\Domain\Vault\Store\StoreEntry;
use App\Domain\Vault\Store\StoreFileTooLarge;
use App\Domain\Vault\Store\StorePage;
use App\Domain\Vault\Store\StoreUnavailable;
use App\Domain\Vault\Store\StoreVersionConflict;
use App\Domain\Vault\Store\VaultPathNormalizer;
use App\Domain\Vault\Store\VaultStore;
use App\Domain\Vault\Store\WorkspaceStorageReadOnly;
use App\Models\TenantVaultStore;
use App\Models\VaultJournalEntry;
use App\Models\VaultRemoteFile;
use App\Models\VaultSyncState;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

/**
 * A workspace vault in a private GitHub repository (D1: one repository and
 * branch per workspace). Write-behind (D3): writes land in `vault_journal` in
 * milliseconds and vault:flush commits them in batches. Reads come from the
 * journal, then the blob cache, then `GET /git/blobs`. stat/list/exists use
 * `vault_remote_files` (the remote head as last pulled) overlaid with the
 * journal, so they never call GitHub.
 *
 * Versions are Git blob SHAs, computed locally for pending writes, so a
 * version stays the same before and after its flush.
 *
 * Empty directories do not exist in Git: makeDirectory() writes a hidden
 * `.gitkeep`, which list() never returns.
 */
final class GitHubVaultStore implements VaultStore
{
    public const MARKER = '.gitkeep';

    public function __construct(
        private readonly TenantVaultStore $record,
        private readonly Workspace $workspace,
        private readonly GitHubClient $client,
        private readonly GitHubBlobCache $blobs = new GitHubBlobCache,
        private readonly ?\Closure $initializer = null,
    ) {}

    public function driver(): string
    {
        return 'github';
    }

    public function record(): TenantVaultStore
    {
        return $this->record;
    }

    public function client(): GitHubClient
    {
        return $this->client;
    }

    public function read(string $path): string
    {
        $path = $this->normalize($path);
        $row = $this->pendingRow($path);
        if ($row !== null) {
            if ($row->op === VaultJournalEntry::DELETE) {
                throw new VaultNoteNotFound($path);
            }

            return (string) $row->content;
        }

        $remote = $this->remoteFile($path);
        if ($remote === null) {
            throw new VaultNoteNotFound($path);
        }

        return $this->blob((string) $remote->blob_sha);
    }

    public function readStream(string $path)
    {
        $contents = $this->read($path);
        $stream = fopen('php://temp/maxmemory:'.(8 * 1024 * 1024), 'w+b');
        if ($stream === false) {
            throw new \RuntimeException('Unable to open a temporary stream.');
        }
        fwrite($stream, $contents);
        rewind($stream);

        return $stream;
    }

    public function stat(string $path): ?StoreEntry
    {
        $path = $this->normalize($path);
        $row = $this->pendingRow($path);
        if ($row !== null) {
            return $row->op === VaultJournalEntry::DELETE
                ? null
                : new StoreEntry($path, (int) $row->size, (string) $row->git_sha, $row->updated_at?->getTimestamp());
        }

        $remote = $this->remoteFile($path);

        return $remote === null ? null : new StoreEntry($path, (int) $remote->size, (string) $remote->blob_sha, $remote->updated_at?->getTimestamp());
    }

    public function exists(string $path): bool
    {
        return $this->stat($path) !== null;
    }

    public function mimeType(string $path): ?string
    {
        if (! $this->exists($path)) {
            return null;
        }

        $sample = substr($this->read($path), 0, 4096);
        $info = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $info->buffer($sample);

        return is_string($mime) ? $mime : null;
    }

    public function isDirectory(string $path): bool
    {
        $path = VaultPathNormalizer::normalize($path, nfc: true, workspaceId: (int) $this->workspace->id, allowRoot: true);
        if ($path === '') {
            return true;
        }

        return $this->list($path, null, 1, includeMarkers: true)->entries !== [];
    }

    public function write(string $path, string $contents, ?string $expectedVersion = null): StoreEntry
    {
        $path = $this->normalize($path);
        $this->assertWritable();

        $limit = $this->maxFileBytes();
        if (strlen($contents) > $limit) {
            throw new StoreFileTooLarge($path, strlen($contents), $limit);
        }

        $gitSha = GitHubGit::blobSha($contents);

        return DB::transaction(function () use ($path, $contents, $expectedVersion, $gitSha): StoreEntry {
            $this->lock();
            $current = $this->stat($path)?->version;
            if ($expectedVersion !== null && $expectedVersion !== ($current ?? '')) {
                throw new StoreVersionConflict($path, $expectedVersion === '' ? null : $expectedVersion, $current);
            }

            $attributes = [
                'op' => VaultJournalEntry::WRITE,
                'content' => $contents,
                'size' => strlen($contents),
                'sha256' => hash('sha256', $contents),
                'git_sha' => $gitSha,
                'actor_id' => $this->actor()['id'],
                'actor_name' => $this->actor()['name'],
            ];

            $open = $this->openRow($path);
            if ($open !== null) {
                // Coalescing (§5.2): the first row's base_version is kept, it detects conflicts.
                $open->forceFill($attributes)->save();
            } else {
                VaultJournalEntry::query()->create($attributes + [
                    'vault_store_id' => $this->record->id,
                    'path' => $path,
                    'base_version' => $this->baseVersion($path),
                ]);
            }

            return new StoreEntry($path, strlen($contents), $gitSha, time());
        });
    }

    public function writeStream(string $path, $stream): StoreEntry
    {
        $limit = $this->maxFileBytes();
        $contents = stream_get_contents($stream, $limit + 1);
        if ($contents === false) {
            throw new \RuntimeException("Unable to read the stream for [{$path}].");
        }
        if (strlen($contents) > $limit) {
            throw new StoreFileTooLarge($path, strlen($contents), $limit);
        }

        return $this->write($path, $contents);
    }

    public function delete(string $path, ?string $expectedVersion = null): void
    {
        $path = $this->normalize($path);
        $this->assertWritable();

        DB::transaction(function () use ($path, $expectedVersion): void {
            $this->lock();
            $current = $this->stat($path)?->version;
            if ($current === null) {
                throw new VaultNoteNotFound($path);
            }
            if ($expectedVersion !== null && $expectedVersion !== $current) {
                throw new StoreVersionConflict($path, $expectedVersion, $current);
            }

            $open = $this->openRow($path);
            if ($open !== null && $open->base_version === '' && ! $this->hasInFlightRow($path)) {
                // Created and deleted before any flush: nothing ever reaches GitHub.
                $open->delete();

                return;
            }

            $attributes = [
                'op' => VaultJournalEntry::DELETE,
                'content' => null,
                'size' => 0,
                'sha256' => null,
                'git_sha' => null,
                'actor_id' => $this->actor()['id'],
                'actor_name' => $this->actor()['name'],
            ];
            if ($open !== null) {
                $open->forceFill($attributes)->save();
            } else {
                VaultJournalEntry::query()->create($attributes + [
                    'vault_store_id' => $this->record->id,
                    'path' => $path,
                    'base_version' => $this->baseVersion($path),
                ]);
            }
        });
    }

    public function move(string $from, string $to): void
    {
        $from = $this->normalize($from);
        $to = $this->normalize($to);
        if (! $this->exists($from)) {
            throw new VaultNoteNotFound($from);
        }
        if ($this->exists($to)) {
            throw new \RuntimeException("Vault file [{$to}] already exists.");
        }

        // Git has no rename: a move is a delete plus an add (§5.4).
        DB::transaction(function () use ($from, $to): void {
            $this->write($to, $this->read($from), '');
            $this->delete($from);
        });
    }

    public function list(string $prefix = '', ?string $cursor = null, int $limit = 1000, bool $includeMarkers = false): StorePage
    {
        $this->ensureInitialized();
        $prefix = VaultPathNormalizer::normalize($prefix, nfc: true, workspaceId: (int) $this->workspace->id, allowRoot: true);
        $limit = max(1, $limit);
        $window = max(50, $limit);
        $after = $cursor;
        $out = [];

        while (true) {
            $remote = $this->scoped(VaultRemoteFile::query(), $prefix, $after)
                ->orderBy('path')->limit($window)->get(['path', 'blob_sha', 'size', 'updated_at']);
            $pendingPaths = $this->scoped(VaultJournalEntry::query()->whereNull('flushed_at'), $prefix, $after)
                ->select('path')->distinct()->orderBy('path')->limit($window)->pluck('path')->all();

            // Only paths up to the smaller "horizon" are complete in both sources.
            $horizons = [];
            if ($remote->count() === $window) {
                $horizons[] = (string) $remote->last()->path;
            }
            if (count($pendingPaths) === $window) {
                $horizons[] = (string) end($pendingPaths);
            }
            $horizon = $horizons === [] ? null : array_reduce($horizons, static fn (?string $carry, string $p): string => $carry === null || strcmp($p, $carry) < 0 ? $p : $carry);

            $merged = [];
            foreach ($remote as $file) {
                $merged[(string) $file->path] = new StoreEntry((string) $file->path, (int) $file->size, (string) $file->blob_sha, $file->updated_at?->getTimestamp());
            }
            if ($pendingPaths !== []) {
                foreach ($this->latestRows($pendingPaths) as $path => $row) {
                    if ($row->op === VaultJournalEntry::DELETE) {
                        unset($merged[$path]);
                    } else {
                        $merged[$path] = new StoreEntry($path, (int) $row->size, (string) $row->git_sha, $row->updated_at?->getTimestamp());
                    }
                }
            }
            ksort($merged, SORT_STRING);

            foreach ($merged as $path => $entry) {
                $path = (string) $path;
                if ($horizon !== null && strcmp($path, $horizon) > 0) {
                    break;
                }
                if (! $includeMarkers && basename($path) === self::MARKER) {
                    continue;
                }
                $out[] = $entry;
                if (count($out) >= $limit) {
                    return new StorePage($out, $path);
                }
            }

            if ($horizon === null) {
                return new StorePage($out, null);
            }
            $after = $horizon;
        }
    }

    public function makeDirectory(string $path): void
    {
        $path = VaultPathNormalizer::normalize($path, nfc: true, workspaceId: (int) $this->workspace->id, allowRoot: true);
        if ($path === '' || $this->isDirectory($path)) {
            return;
        }

        $this->write($path.'/'.self::MARKER, '');
    }

    public function deleteDirectory(string $path): void
    {
        $path = VaultPathNormalizer::normalize($path, nfc: true, workspaceId: (int) $this->workspace->id, allowRoot: true);

        $cursor = null;
        do {
            $page = $this->list($path, $cursor, 500, includeMarkers: true);
            foreach ($page->entries as $entry) {
                try {
                    $this->delete($entry->path);
                } catch (VaultNoteNotFound) {
                    // Already gone.
                }
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
    }

    public function capabilities(): StoreCapabilities
    {
        return new StoreCapabilities(
            conditionalWrite: true,
            emptyDirs: false,
            maxFileBytes: $this->maxFileBytes(),
            writeBehind: true,
            presignedUrls: false,
        );
    }

    /** Content of a blob: cache first, then the API (verified against its SHA). */
    public function blob(string $sha): string
    {
        $cached = $this->blobs->get($this->record, $sha);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $contents = $this->client->blob($sha);
        } catch (GitHubApiError $error) {
            throw new StoreUnavailable('GitHub could not serve this file right now: '.$error->getMessage());
        }
        if (GitHubGit::blobSha($contents) !== $sha) {
            throw new StoreUnavailable("GitHub returned a blob whose content does not match [{$sha}].");
        }
        $this->blobs->put($this->record, $sha, $contents);

        return $contents;
    }

    /** Pending (unflushed) journal rows, oldest first. */
    public function pendingCount(): int
    {
        return VaultJournalEntry::query()->where('vault_store_id', $this->record->id)->whereNull('flushed_at')->count();
    }

    private function normalize(string $path): string
    {
        return VaultPathNormalizer::normalize($path, nfc: true, workspaceId: (int) $this->workspace->id);
    }

    private function maxFileBytes(): int
    {
        return max(1, min(100 * 1024 * 1024, (int) config('jotter.github.max_file_bytes', 10 * 1024 * 1024)));
    }

    private function assertWritable(): void
    {
        $status = TenantVaultStore::query()->whereKey($this->record->id)->value('status');
        if ($status === TenantVaultStore::STATUS_FAILED) {
            throw new WorkspaceStorageReadOnly((int) $this->record->id);
        }
    }

    /** Serializes journal changes of this store (one row lock on its sync state). */
    private function lock(): void
    {
        VaultSyncState::query()->firstOrCreate(['vault_store_id' => $this->record->id]);
        VaultSyncState::query()->where('vault_store_id', $this->record->id)->lockForUpdate()->first();
    }

    private function ensureInitialized(): void
    {
        if ($this->initializer === null) {
            return;
        }
        $initialized = VaultSyncState::query()->where('vault_store_id', $this->record->id)->value('initialized');
        if (! $initialized) {
            ($this->initializer)($this->record);
        }
    }

    private function remoteFile(string $path): ?VaultRemoteFile
    {
        $this->ensureInitialized();

        return VaultRemoteFile::query()->where('vault_store_id', $this->record->id)->where('path', $path)->first();
    }

    /** Latest unflushed row of a path (an open row wins over one being flushed). */
    private function pendingRow(string $path): ?VaultJournalEntry
    {
        return VaultJournalEntry::query()
            ->where('vault_store_id', $this->record->id)
            ->where('path', $path)
            ->whereNull('flushed_at')
            ->orderByDesc('id')
            ->first();
    }

    /** The unflushed row no flush has claimed yet: the one new edits coalesce into. */
    private function openRow(string $path): ?VaultJournalEntry
    {
        return VaultJournalEntry::query()
            ->where('vault_store_id', $this->record->id)
            ->where('path', $path)
            ->whereNull('flushed_at')
            ->whereNull('flush_id')
            ->orderByDesc('id')
            ->first();
    }

    private function hasInFlightRow(string $path): bool
    {
        return VaultJournalEntry::query()
            ->where('vault_store_id', $this->record->id)
            ->where('path', $path)
            ->whereNull('flushed_at')
            ->whereNotNull('flush_id')
            ->exists();
    }

    /** What the remote holds for $path once in-flight rows land: '' = nothing. */
    private function baseVersion(string $path): string
    {
        $inFlight = VaultJournalEntry::query()
            ->where('vault_store_id', $this->record->id)
            ->where('path', $path)
            ->whereNull('flushed_at')
            ->whereNotNull('flush_id')
            ->orderByDesc('id')
            ->first();
        if ($inFlight !== null) {
            return $inFlight->op === VaultJournalEntry::DELETE ? '' : (string) $inFlight->git_sha;
        }

        return (string) ($this->remoteFile($path)?->blob_sha ?? '');
    }

    /**
     * @param  list<string>  $paths
     * @return array<string, VaultJournalEntry>
     */
    private function latestRows(array $paths): array
    {
        $rows = VaultJournalEntry::query()
            ->where('vault_store_id', $this->record->id)
            ->whereNull('flushed_at')
            ->whereIn('path', $paths)
            ->orderBy('id')
            ->get(['id', 'path', 'op', 'size', 'git_sha', 'updated_at']);

        $latest = [];
        foreach ($rows as $row) {
            $latest[(string) $row->path] = $row;
        }

        return $latest;
    }

    /**
     * @template T of \Illuminate\Database\Eloquent\Builder
     *
     * @param  T  $query
     * @return T
     */
    private function scoped($query, string $prefix, ?string $after)
    {
        $query->where('vault_store_id', $this->record->id);
        if ($prefix !== '') {
            $query->where('path', 'like', str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $prefix).'/%');
        }
        if ($after !== null) {
            $query->where('path', '>', $after);
        }

        return $query;
    }

    /**
     * Who is writing, for the commit message (G2: display name only).
     *
     * @return array{id: ?string, name: ?string}
     */
    private function actor(): array
    {
        $subject = request()->attributes->get('authenticated_subject');
        if ($subject instanceof \App\Domain\Auth\AuthenticatedSubject) {
            return ['id' => mb_substr($subject->subjectId, 0, 191), 'name' => mb_substr($subject->name, 0, 191)];
        }

        return ['id' => null, 'name' => null];
    }
}
