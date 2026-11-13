<?php

namespace App\Domain\Vault\Store\GitHub;

use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditRecorder;
use App\Models\Attachment;
use App\Models\Note;
use App\Models\TenantVaultStore;
use App\Models\VaultJournalEntry;
use App\Models\VaultRemoteFile;
use App\Models\VaultSyncState;
use App\Models\Workspace;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Sends the journal to GitHub in batched commits (plan §5.4), one store at a
 * time, under the same lock as the pull:
 *
 *  1. claim up to N pending paths (`flush_id`);
 *  2. `GET` the ref; if the head moved since the last pull, pull first
 *     (conflicts are resolved there) and start again;
 *  3. `POST /git/blobs` for binaries, `POST /git/trees` on top of the head's
 *     tree, `POST /git/commits` with the head as parent;
 *  4. record the commit in `vault_sync_states` *before* the ref update;
 *  5. `PATCH /git/refs` (never forced): 422 = someone pushed, pull and redo;
 *  6. verify every path of the new tree against the blob SHA computed locally,
 *     then mark the rows flushed.
 *
 * A crash between 4 and 5 is recovered on the next run: if the head is the
 * recorded commit the batch is done, if it is still the parent only the
 * PATCH is repeated, otherwise the orphan commit is abandoned (harmless).
 *
 * Cost: 3 writes per batch + 1 per binary file. Out of budget = the flush waits
 * (1 → 5 → 15 min); the user's save never fails.
 */
class GitHubFlusher
{
    public function __construct(
        private readonly GitHubStoreFactory $factory,
        private readonly GitHubSync $sync,
        private readonly GitHubWriteBudget $budget = new GitHubWriteBudget,
        private readonly AuditRecorder $audit = new AuditRecorder,
        private readonly GitHubSyncNotifier $notifier = new GitHubSyncNotifier,
    ) {}

    /**
     * Flush every GitHub store with pending work, oldest flush first (round robin).
     *
     * @return list<array{store: int, status: string, commits: int, files: int}>
     */
    public function flushAll(?float $deadline = null): array
    {
        $storeIds = VaultJournalEntry::query()->whereNull('flushed_at')->distinct()->pluck('vault_store_id')
            ->merge(VaultSyncState::query()->whereNotNull('pending_commit_sha')->pluck('vault_store_id'))
            ->unique()->all();

        $stores = TenantVaultStore::query()->whereIn('id', $storeIds)->where('driver', 'github')->get()
            ->sortBy(fn (TenantVaultStore $store) => VaultSyncState::query()->where('vault_store_id', $store->id)->value('last_flush_at') ?? '0000');

        $results = [];
        foreach ($stores as $store) {
            if ($deadline !== null && microtime(true) > $deadline) {
                break;
            }
            $results[] = ['store' => (int) $store->id] + $this->flush($store, deadline: $deadline);
        }

        $this->prune();

        return $results;
    }

    /**
     * @return array{status: string, commits: int, files: int}
     */
    public function flush(TenantVaultStore $record, int $maxBatches = 5, ?float $deadline = null, bool $ignoreBackoff = false): array
    {
        $lock = Cache::lock('vault-sync:'.$record->id, 300);
        if (! $lock->get()) {
            return ['status' => 'locked', 'commits' => 0, 'files' => 0];
        }

        try {
            return $this->flushLocked($record, $maxBatches, $deadline, $ignoreBackoff);
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{status: string, commits: int, files: int}
     */
    private function flushLocked(TenantVaultStore $record, int $maxBatches, ?float $deadline, bool $ignoreBackoff): array
    {
        $record->refresh();
        $state = VaultSyncState::for($record);
        if ($record->status === TenantVaultStore::STATUS_FAILED) {
            return ['status' => 'read_only', 'commits' => 0, 'files' => 0];
        }
        if (! $ignoreBackoff && $state->next_flush_at !== null && $state->next_flush_at->isFuture()) {
            return ['status' => 'waiting', 'commits' => 0, 'files' => 0];
        }

        $client = $this->factory->client($record);
        $branch = $this->factory->branch($record);
        $commits = 0;
        $files = 0;
        $flushId = null;

        try {
            $commits += $this->recover($record, $state, $client, $branch);

            for ($batch = 0, $restarts = 0; $batch < $maxBatches; $batch++) {
                if ($deadline !== null && microtime(true) > $deadline) {
                    break;
                }

                $rows = $this->claimable($record);
                if ($rows->isEmpty()) {
                    break;
                }
                $effective = $this->effective($rows);
                $binaries = $effective->filter(fn (VaultJournalEntry $row): bool => $row->op === VaultJournalEntry::WRITE && ! GitHubGit::isInlineText((string) $row->content))->count();
                $needed = 3 + $binaries + 1;
                if ($this->budget->available($client->actorKey()) < $needed) {
                    $wait = max(60, $this->budget->waitFor($client->actorKey(), $needed));
                    $this->delay($state, $wait, 'GitHub write budget exhausted; the flush waits.');

                    return ['status' => 'delayed', 'commits' => $commits, 'files' => $files];
                }

                $flushId = (string) Str::uuid();
                VaultJournalEntry::query()->whereIn('id', $rows->pluck('id'))->whereNull('flush_id')->update(['flush_id' => $flushId]);

                $ref = $client->ref($branch, $state->remote_head !== null ? $state->ref_etag : null);
                $head = match ($ref->status) {
                    GitHubRef::NOT_MODIFIED => $state->remote_head,
                    GitHubRef::OK => $ref->sha,
                    default => null,
                };
                $moved = ($ref->status === GitHubRef::OK && $ref->sha !== $state->remote_head)
                    || ($head === null && $state->remote_head !== null)
                    || ! $state->initialized;
                if ($moved) {
                    // Someone pushed since the last pull: bring it in first (§5.6), then redo the batch.
                    $this->release($flushId);
                    $flushId = null;
                    $pulled = $this->sync->pullLocked($record);
                    if (in_array($pulled['status'], ['failed', 'rate_limited'], true)) {
                        $state->refresh();
                        throw new GitHubApiError((string) $state->last_error, 0, $pulled['status'] === 'rate_limited' ? 60 : null);
                    }
                    $state->refresh();
                    if (++$restarts > 3) {
                        break;
                    }
                    $batch--;

                    continue;
                }

                if ($head === null) {
                    $done = $this->bootstrap($record, $state, $client, $branch, $ref, $effective, $flushId);
                } else {
                    $done = $this->commitBatch($record, $state, $client, $branch, $head, $effective, $flushId);
                }
                $flushId = null;
                if ($done === null) {
                    // Not a fast-forward: pull happened, try again.
                    if (++$restarts > 3) {
                        break;
                    }
                    $batch--;

                    continue;
                }
                $commits += $done > 0 ? 1 : 0;
                $files += $effective->count();
            }
        } catch (GitHubApiError $error) {
            if ($flushId !== null) {
                $this->release($flushId);
            }

            return $this->failure($record, $state, $error) + ['commits' => $commits, 'files' => $files];
        }

        $state->refresh();
        $state->forceFill([
            'last_flush_at' => now(),
            'flush_failures' => 0,
            'failing_since' => null,
            'failure_notified_at' => null,
            'next_flush_at' => null,
            'last_error' => null,
        ])->save();

        return ['status' => 'flushed', 'commits' => $commits, 'files' => $files];
    }

    /**
     * Open journal rows, oldest first: at most N distinct paths.
     *
     * @return Collection<int, VaultJournalEntry>
     */
    private function claimable(TenantVaultStore $record): Collection
    {
        $limit = max(1, (int) config('jotter.github.flush_batch', 100));
        $paths = VaultJournalEntry::query()->where('vault_store_id', $record->id)
            ->whereNull('flushed_at')->whereNull('flush_id')
            ->groupBy('path')->orderByRaw('MIN(id)')->limit($limit)->pluck('path')->all();
        if ($paths === []) {
            return collect();
        }

        return VaultJournalEntry::query()->where('vault_store_id', $record->id)
            ->whereNull('flushed_at')->whereNull('flush_id')->whereIn('path', $paths)
            ->orderBy('id')->get();
    }

    /**
     * The latest row per path: what the batch sends.
     *
     * @param  Collection<int, VaultJournalEntry>  $rows
     * @return Collection<string, VaultJournalEntry>
     */
    private function effective(Collection $rows): Collection
    {
        $latest = [];
        foreach ($rows as $row) {
            $latest[(string) $row->path] = $row;
        }

        return collect($latest);
    }

    /**
     * @param  Collection<string, VaultJournalEntry>  $effective
     * @return int|null commits made (0 = nothing to send), null = ref moved meanwhile
     */
    private function commitBatch(TenantVaultStore $record, VaultSyncState $state, GitHubClient $client, string $branch, string $head, Collection $effective, string $flushId): ?int
    {
        $known = VaultRemoteFile::query()->where('vault_store_id', $record->id)
            ->whereIn('path', $effective->keys()->all())->pluck('blob_sha', 'path')->all();

        $entries = [];
        foreach ($effective as $path => $row) {
            $path = (string) $path;
            if ($row->op === VaultJournalEntry::DELETE) {
                if (isset($known[$path])) {
                    $entries[] = ['path' => $path, 'mode' => '100644', 'type' => 'blob', 'sha' => null];
                }

                continue;
            }
            if (($known[$path] ?? null) === $row->git_sha) {
                continue; // GitHub already has these bytes.
            }
            $contents = (string) $row->content;
            $entries[] = GitHubGit::isInlineText($contents)
                ? ['path' => $path, 'mode' => '100644', 'type' => 'blob', 'content' => $contents]
                : ['path' => $path, 'mode' => '100644', 'type' => 'blob', 'sha' => $client->createBlob($contents)];
        }

        if ($entries === []) {
            $this->markFlushed($record, $flushId, null, []);

            return 0;
        }

        $baseTree = $client->commit($head)['tree'];
        $tree = $client->createTree($baseTree, $entries);
        $commit = $client->createCommit($this->message($effective), $tree, [$head]);

        // Step 7: remember the commit before moving the ref (crash recovery).
        $state->forceFill(['pending_flush_id' => $flushId, 'pending_commit_sha' => $commit, 'pending_commit_parent' => $head])->save();
        VaultJournalEntry::query()->where('flush_id', $flushId)->update(['commit_sha' => $commit]);

        try {
            $client->updateRef($branch, $commit);
        } catch (GitHubApiError $error) {
            if (! $error->isNotFastForward()) {
                throw $error;
            }
            $this->abandon($state, $flushId);
            $this->sync->pullLocked($record);
            $state->refresh();

            return null;
        }

        $this->finish($record, $state, $client, $flushId, $commit);

        return 1;
    }

    /**
     * No branch head yet. An empty repository refuses the Git database API
     * (409), so its first commit goes through `PUT /contents` with one file;
     * a repository whose branch does not exist yet gets a root commit and a new ref.
     *
     * @param  Collection<string, VaultJournalEntry>  $effective
     */
    private function bootstrap(TenantVaultStore $record, VaultSyncState $state, GitHubClient $client, string $branch, GitHubRef $ref, Collection $effective, string $flushId): int
    {
        $writes = $effective->filter(fn (VaultJournalEntry $row): bool => $row->op === VaultJournalEntry::WRITE);
        if ($writes->isEmpty()) {
            // Only deletes of files GitHub never had.
            $this->markFlushed($record, $flushId, null, []);

            return 0;
        }

        if ($ref->status === GitHubRef::EMPTY) {
            $first = $writes->first();
            $commit = $client->createFirstFile($branch, (string) $first->path, (string) $first->content, $this->message(collect([(string) $first->path => $first])));
            // Only this path is done; the rest of the batch goes on top of it.
            $others = $effective->keys()->reject(fn ($path): bool => $path === $first->path)->all();
            VaultJournalEntry::query()->where('flush_id', $flushId)->whereIn('path', $others)->update(['flush_id' => null]);
            $this->finish($record, $state, $client, $flushId, $commit);

            return 1;
        }

        $entries = [];
        foreach ($writes as $path => $row) {
            $contents = (string) $row->content;
            $entries[] = GitHubGit::isInlineText($contents)
                ? ['path' => (string) $path, 'mode' => '100644', 'type' => 'blob', 'content' => $contents]
                : ['path' => (string) $path, 'mode' => '100644', 'type' => 'blob', 'sha' => $client->createBlob($contents)];
        }
        $tree = $client->createTree(null, $entries);
        $commit = $client->createCommit($this->message($effective), $tree, []);
        $state->forceFill(['pending_flush_id' => $flushId, 'pending_commit_sha' => $commit, 'pending_commit_parent' => null])->save();
        $client->createRef($branch, $commit);
        $this->finish($record, $state, $client, $flushId, $commit);

        return 1;
    }

    /**
     * The ref now points at $commit: verify every flushed path against the
     * locally computed blob SHA, update the manifest and mark rows flushed.
     */
    private function finish(TenantVaultStore $record, VaultSyncState $state, GitHubClient $client, string $flushId, string $commit): void
    {
        $treeSha = $client->commit($commit)['tree'];
        $tree = [];
        foreach ($client->tree($treeSha)['entries'] as $entry) {
            if ($entry['type'] === 'blob') {
                $tree[$entry['path']] = $entry;
            }
        }

        $rows = VaultJournalEntry::query()->where('flush_id', $flushId)->orderBy('id')->get();
        $verified = [];
        foreach ($this->effective($rows) as $path => $row) {
            $path = (string) $path;
            if ($row->op === VaultJournalEntry::WRITE) {
                $remote = $tree[$path]['sha'] ?? null;
                if ($remote !== $row->git_sha) {
                    $this->abandon($state, $flushId);
                    throw new GitHubApiError("Verification failed for [{$path}]: GitHub has blob [".($remote ?? 'none')."], Jotter computed [{$row->git_sha}].");
                }
                $verified[$path] = ['sha' => $remote, 'size' => (int) ($tree[$path]['size'] ?? $row->size)];
            } else {
                $verified[$path] = null;
            }
        }

        $this->markFlushed($record, $flushId, $commit, $verified);
        $state->refresh();
        $state->forceFill([
            'remote_head' => $commit,
            'ref_etag' => null,
            'initialized' => true,
            'pending_flush_id' => null,
            'pending_commit_sha' => null,
            'pending_commit_parent' => null,
            'last_flush_at' => now(),
        ])->save();
    }

    /**
     * @param  array<string, array{sha: string, size: int}|null>  $verified
     */
    private function markFlushed(TenantVaultStore $record, string $flushId, ?string $commit, array $verified): void
    {
        $workspaceId = Workspace::query()->where('vault_store_id', $record->id)->value('id');
        foreach ($verified as $path => $file) {
            if ($file === null) {
                VaultRemoteFile::query()->where('vault_store_id', $record->id)->where('path', $path)->delete();

                continue;
            }
            $updated = VaultRemoteFile::query()->where('vault_store_id', $record->id)->where('path', $path)
                ->update(['blob_sha' => $file['sha'], 'size' => $file['size'], 'updated_at' => now()]);
            if ($updated === 0) {
                VaultRemoteFile::query()->create(['vault_store_id' => $record->id, 'path' => $path, 'blob_sha' => $file['sha'], 'size' => $file['size']]);
            }
            if ($workspaceId !== null) {
                foreach ([Note::withTrashed(), Attachment::query()] as $query) {
                    $query->where('workspace_id', $workspaceId)->where('path', $path)->toBase()->update(['remote_version' => $file['sha']]);
                }
            }
        }

        // Flushed bytes leave MySQL at once (they are in Git now); rows stay 7 days for diagnosis.
        VaultJournalEntry::query()->where('flush_id', $flushId)->update([
            'flushed_at' => now(),
            'commit_sha' => $commit,
            'content' => null,
            'last_error' => null,
        ]);
    }

    /**
     * Finish or abandon a batch interrupted between the commit and the ref update.
     */
    private function recover(TenantVaultStore $record, VaultSyncState $state, GitHubClient $client, string $branch): int
    {
        // Claims without a recorded commit died before step 7: nothing reached GitHub's ref.
        VaultJournalEntry::query()->where('vault_store_id', $record->id)->whereNull('flushed_at')->whereNotNull('flush_id')
            ->when($state->pending_flush_id !== null, fn ($query) => $query->where('flush_id', '!=', $state->pending_flush_id))
            ->update(['flush_id' => null, 'commit_sha' => null]);

        if ($state->pending_commit_sha === null) {
            return 0;
        }

        $flushId = (string) $state->pending_flush_id;
        $commit = (string) $state->pending_commit_sha;
        $ref = $client->ref($branch);

        if ($ref->status === GitHubRef::OK && $ref->sha === $commit) {
            $this->finish($record, $state, $client, $flushId, $commit);

            return 1;
        }

        if ($ref->status === GitHubRef::OK && $state->pending_commit_parent !== null && $ref->sha === $state->pending_commit_parent) {
            try {
                $client->updateRef($branch, $commit);
                $this->finish($record, $state, $client, $flushId, $commit);

                return 1;
            } catch (GitHubApiError $error) {
                if (! $error->isNotFastForward()) {
                    throw $error;
                }
            }
        }

        if ($ref->status !== GitHubRef::OK && $state->pending_commit_parent === null) {
            try {
                $client->createRef($branch, $commit);
                $this->finish($record, $state, $client, $flushId, $commit);

                return 1;
            } catch (GitHubApiError $error) {
                if ($error->status !== 422) {
                    throw $error;
                }
            }
        }

        // The head moved elsewhere: the orphan commit is harmless; the rows go back to the queue.
        $this->abandon($state, $flushId);

        return 0;
    }

    private function abandon(VaultSyncState $state, string $flushId): void
    {
        $this->release($flushId);
        $state->forceFill(['pending_flush_id' => null, 'pending_commit_sha' => null, 'pending_commit_parent' => null])->save();
    }

    private function release(string $flushId): void
    {
        VaultJournalEntry::query()->where('flush_id', $flushId)->whereNull('flushed_at')->update(['flush_id' => null, 'commit_sha' => null]);
    }

    private function delay(VaultSyncState $state, int $seconds, string $reason): void
    {
        $state->refresh();
        $state->forceFill(['next_flush_at' => now()->addSeconds($seconds), 'last_error' => $reason])->save();
    }

    /**
     * @return array{status: string}
     */
    private function failure(TenantVaultStore $record, VaultSyncState $state, GitHubApiError $error): array
    {
        $state->refresh();
        $message = mb_substr($error->getMessage(), 0, 1000);

        if ($error->isRateLimited()) {
            // 1 → 5 → 15 min as the budget stays low (§5.7).
            $wait = max((int) $error->retryAfter, match (true) {
                $state->flush_failures >= 2 => 900,
                $state->flush_failures >= 1 => 300,
                default => 60,
            });
            $state->forceFill(['next_flush_at' => now()->addSeconds($wait), 'last_error' => $message])->save();

            return ['status' => 'delayed'];
        }

        if ($error->isPermanent()) {
            $this->factory->markFailed($record, $error->getMessage());
            $state->forceFill(['last_error' => $message])->save();

            return ['status' => 'failed'];
        }

        $failures = $state->flush_failures + 1;
        $minutes = min(30, 2 ** ($failures - 1));
        $since = $state->failing_since ?? now();
        $state->forceFill([
            'flush_failures' => $failures,
            'failing_since' => $since,
            'next_flush_at' => now()->addMinutes($minutes),
            'last_error' => $message,
        ])->save();
        VaultJournalEntry::query()->where('vault_store_id', $record->id)->whereNull('flushed_at')
            ->update(['attempts' => \Illuminate\Support\Facades\DB::raw('attempts + 1'), 'last_error' => mb_substr($message, 0, 1000)]);

        $workspace = Workspace::query()->where('vault_store_id', $record->id)->first();
        if ($failures === 1) {
            $this->audit->record(AuditEvent::VAULT_FLUSH_FAILED, (int) $record->tenant_id, $workspace?->id, null, [
                'vault_store_id' => $record->id,
                'error' => mb_substr($message, 0, 500),
            ]);
        }
        if ($since->lte(now()->subDay()) && $state->failure_notified_at === null) {
            $this->audit->record(AuditEvent::VAULT_FLUSH_FAILED, (int) $record->tenant_id, $workspace?->id, null, [
                'vault_store_id' => $record->id,
                'error' => mb_substr($message, 0, 500),
                'failing_since' => $since->toIso8601String(),
            ]);
            $this->notifier->aboutStore($record, $workspace?->id, 'flush_failing', __('messages.github_flush_failing', ['name' => $record->name]), ['error' => mb_substr($message, 0, 500)]);
            $state->forceFill(['failure_notified_at' => now()])->save();
        }

        return ['status' => 'retrying'];
    }

    /**
     * G2: display names only, never e-mail. "jotter: 3 notas por Ana (via Jotter)".
     *
     * @param  Collection<string, VaultJournalEntry>  $rows
     */
    public function message(Collection $rows): string
    {
        $names = $rows->pluck('actor_name')->filter(fn ($name): bool => is_string($name) && trim($name) !== '')
            ->map(fn (string $name): string => trim(str_replace(["\n", "\r"], ' ', $name)))->unique()->values();
        $who = match (true) {
            $names->isEmpty() => 'Jotter',
            $names->count() <= 3 => $names->join(', ', ' & '),
            default => $names->take(2)->join(', ').' +'.($names->count() - 2),
        };

        return trans_choice('messages.github_commit_message', $rows->count(), ['count' => $rows->count(), 'names' => $who]);
    }

    /** Flushed rows are kept 7 days for diagnosis, without content. */
    private function prune(): void
    {
        VaultJournalEntry::query()->whereNotNull('flushed_at')->where('flushed_at', '<', now()->subDays(7))->delete();
    }
}
