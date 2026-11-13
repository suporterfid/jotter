<?php

namespace App\Domain\Vault\Move;

use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Vault\Store\StoreEntry;
use App\Domain\Vault\Store\VaultStore;
use App\Domain\Vault\Store\VaultStoreDrivers;
use App\Domain\Vault\Store\VaultStoreResolver;
use App\Models\Attachment;
use App\Models\Note;
use App\Models\TenantVaultStore;
use App\Models\VaultMove;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

/**
 * Changes the store that holds a workspace's vault (D9, #437), for any pair of
 * drivers, local ↔ remote included (the anti-lock-in exit):
 *
 * 1. preflight: destination writable (probe), empty, files fit the driver limit;
 * 2. the workspace turns `moving`: writes answer 423, reads stay on the source;
 * 3. copy in bounded batches from cron (`vault:process-moves`), resumable by cursor;
 * 4. every copy is verified by SHA-256 of the bytes read back;
 * 5. atomic swap of `vault_store_id`/`vault_prefix`, recording `remote_version`;
 * 6. the source stays until `retain_until` for `--rollback`; `vault:finalize-moves`
 *    deletes it afterwards, audited.
 *
 * The engine never knows the driver: it only talks to VaultStore.
 */
final class VaultMover
{
    public function __construct(
        private readonly VaultStoreResolver $resolver,
        private readonly AuditRecorder $audit = new AuditRecorder,
        private readonly VaultStoreDrivers $drivers = new VaultStoreDrivers,
    ) {}

    /**
     * Preflight and start a move. $target null = back to the workspace's local folder.
     *
     * @throws VaultMoveRefused
     */
    public function start(Workspace $workspace, ?TenantVaultStore $target, ?string $actorId = null): VaultMove
    {
        $workspace->refresh();

        if ($workspace->isMovingStorage() || VaultMove::query()->where('workspace_id', $workspace->id)->where('status', VaultMove::COPYING)->exists()) {
            throw new VaultMoveRefused('the workspace is already moving.');
        }
        if ($target !== null && (int) $target->tenant_id !== (int) $workspace->tenant_id) {
            throw new VaultMoveRefused('the store belongs to another tenant.');
        }
        if ($target === null && $workspace->vault_store_id === null) {
            throw new VaultMoveRefused('the workspace already uses its local folder.');
        }
        if ($target !== null && (int) $workspace->vault_store_id === (int) $target->id) {
            throw new VaultMoveRefused('the workspace already uses this store.');
        }
        if ($target !== null && ! $this->drivers->isImplemented($target->driver)) {
            throw new VaultMoveRefused("the {$target->driver} driver is not available in this version.");
        }
        if ($target !== null && $target->driver === VaultStoreDrivers::GITHUB) {
            // D1: a repository holds exactly one workspace.
            $taken = Workspace::query()->where('vault_store_id', $target->id)->whereKeyNot($workspace->id)->exists()
                || VaultMove::query()->where('to_store_id', $target->id)->whereIn('status', [VaultMove::COPYING, VaultMove::COMPLETED])
                    ->where('workspace_id', '!=', $workspace->id)->exists();
            if ($taken) {
                throw new VaultMoveRefused('this GitHub repository already holds another workspace.');
            }
            if ($target->status === TenantVaultStore::STATUS_FAILED) {
                throw new VaultMoveRefused('the GitHub store failed its last check; test the connection first.');
            }
        }

        $toPrefix = $target === null ? '' : $this->prefixFor($workspace, $target);
        $source = $this->resolver->for($workspace);
        $destination = $target === null ? $this->resolver->local($workspace) : $this->resolver->forStore($target, $workspace, $toPrefix);

        [$files, $bytes, $largest] = $this->measure($source);
        $limit = $destination->capabilities()->maxFileBytes;
        if ($limit !== null && $largest > $limit) {
            throw new VaultMoveRefused("a file of {$largest} bytes is over the destination limit of {$limit} bytes.");
        }

        if ($target === null) {
            $this->releaseStaleLocalCopy($workspace, $actorId);
        }

        $this->probe($destination);
        if ($destination->list('', null, 1)->entries !== []) {
            throw new VaultMoveRefused('the destination is not empty.');
        }

        $move = DB::transaction(function () use ($workspace, $target, $toPrefix, $files, $bytes, $actorId): VaultMove {
            $move = VaultMove::create([
                'workspace_id' => $workspace->id,
                'from_store_id' => $workspace->vault_store_id,
                'from_prefix' => (string) ($workspace->vault_prefix ?? ''),
                'to_store_id' => $target?->id,
                'to_prefix' => $toPrefix,
                'status' => VaultMove::COPYING,
                'files_total' => $files,
                'bytes_total' => $bytes,
                'actor_id' => $actorId,
                'started_at' => now(),
            ]);
            $workspace->forceFill(['storage_state' => 'moving'])->save();

            return $move;
        });

        $this->audit->record(AuditEvent::VAULT_MOVE_STARTED, $workspace->tenant_id, $workspace->id, $actorId, [
            'move_id' => $move->id,
            'from_store_id' => $move->from_store_id,
            'to_store_id' => $move->to_store_id,
            'files' => $files,
            'bytes' => $bytes,
        ]);

        return $move;
    }

    /**
     * Copy up to $limit files of a move; completes it (verify + swap) when the
     * source is exhausted. Safe to call again after an interruption.
     */
    public function process(VaultMove $move, int $limit = 200): VaultMove
    {
        if ($move->status !== VaultMove::COPYING) {
            return $move;
        }

        $workspace = $move->workspace()->firstOrFail();

        try {
            [$source, $destination] = $this->ends($move, $workspace);
            $page = $source->list('', $move->cursor, max(1, $limit));

            foreach ($page->entries as $entry) {
                $copied = $this->copyVerified($source, $destination, $entry);
                $this->recordRemoteVersion($workspace, $copied);

                $move->forceFill([
                    'cursor' => $entry->path,
                    'files_copied' => $move->files_copied + 1,
                    'bytes_copied' => $move->bytes_copied + $copied->size,
                ])->save();
            }

            if ($page->nextCursor === null) {
                $this->complete($move, $workspace, $source, $destination);
            }
        } catch (\Throwable $exception) {
            $this->fail($move, $workspace, $exception);
        }

        return $move->fresh();
    }

    /**
     * Process every copying move, at most $limit files in total (cron entry point).
     *
     * @return list<VaultMove>
     */
    public function processPending(int $limit = 200): array
    {
        $done = [];
        foreach (VaultMove::query()->where('status', VaultMove::COPYING)->orderBy('id')->get() as $move) {
            if ($limit <= 0) {
                break;
            }
            $before = $move->files_copied;
            $move = $this->process($move, $limit);
            $limit -= max(1, $move->files_copied - $before);
            $done[] = $move;
        }

        return $done;
    }

    /**
     * Run a whole move synchronously (CLI `--sync` and tests).
     */
    public function runToCompletion(VaultMove $move, int $batch = 200): VaultMove
    {
        while ($move->status === VaultMove::COPYING) {
            $move = $this->process($move, $batch);
        }

        return $move;
    }

    /**
     * Put the workspace back on the source of a completed move, while the source
     * is still retained. Refused when notes changed after the move unless $force:
     * those changes live only in the destination, which is then kept.
     *
     * @throws VaultMoveRefused
     */
    public function rollback(VaultMove $move, ?string $actorId = null, bool $force = false): VaultMove
    {
        if ($move->status !== VaultMove::COMPLETED) {
            throw new VaultMoveRefused('only a completed move whose source is still kept can be rolled back.');
        }

        $workspace = $move->workspace()->firstOrFail();
        if ((int) ($workspace->vault_store_id ?? 0) !== (int) ($move->to_store_id ?? 0)) {
            throw new VaultMoveRefused('the workspace has moved again since.');
        }

        [, $destination] = $this->ends($move, $workspace);

        $changed = $this->changedSince($move, $workspace, $destination);
        if ($changed && ! $force) {
            throw new VaultMoveRefused('notes or attachments changed after the move; rolling back would hide those changes (use --force to keep them only in the destination).');
        }

        DB::transaction(function () use ($move, $workspace): void {
            $workspace->forceFill([
                'vault_store_id' => $move->from_store_id,
                'vault_prefix' => $move->from_prefix,
                'storage_state' => 'active',
            ])->save();
            $move->forceFill(['status' => VaultMove::ROLLED_BACK, 'retain_until' => null])->save();
        });

        if (! $changed) {
            $destination->deleteDirectory('');
        }
        $this->resetRemoteVersions($workspace);

        $this->audit->record(AuditEvent::VAULT_MOVE_ROLLED_BACK, $workspace->tenant_id, $workspace->id, $actorId, [
            'move_id' => $move->id,
            'destination_kept' => $changed,
        ]);

        return $move->fresh();
    }

    /**
     * Delete the retained source of completed moves whose retention ended
     * ($now = true: every completed move, regardless of retention).
     *
     * @return list<VaultMove>
     */
    public function finalizeDue(bool $now = false, ?string $actorId = null): array
    {
        $moves = VaultMove::query()->where('status', VaultMove::COMPLETED)
            ->when(! $now, fn ($query) => $query->where('retain_until', '<=', now()))
            ->orderBy('id')->get();

        $finalized = [];
        foreach ($moves as $move) {
            $finalized[] = $this->finalize($move, $actorId);
        }

        return $finalized;
    }

    public function finalize(VaultMove $move, ?string $actorId = null): VaultMove
    {
        if ($move->status !== VaultMove::COMPLETED) {
            return $move;
        }

        $workspace = $move->workspace()->firstOrFail();
        $this->sourceView($move, $workspace)->deleteDirectory('');
        $move->forceFill(['status' => VaultMove::FINALIZED, 'retain_until' => null])->save();

        $this->audit->record(AuditEvent::VAULT_MOVE_FINALIZED, $workspace->tenant_id, $workspace->id, $actorId, [
            'move_id' => $move->id,
            'from_store_id' => $move->from_store_id,
        ]);

        return $move->fresh();
    }

    private function complete(VaultMove $move, Workspace $workspace, VaultStore $source, VaultStore $destination): void
    {
        // Final check: every source file is in the destination with the same size.
        // Files that appeared in the destination meanwhile (an external push to the
        // repository) are kept and indexed after the swap, never deleted.
        $files = 0;
        $cursor = null;
        do {
            $page = $source->list('', $cursor, 1000);
            foreach ($page->entries as $entry) {
                $files++;
                $copy = $destination->stat($entry->path);
                if ($copy === null || $copy->size !== $entry->size) {
                    throw new \RuntimeException("Verification failed: [{$entry->path}] is missing or differs in the destination.");
                }
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
        [$copied] = $this->measure($destination);
        $extras = $copied > $files;

        DB::transaction(function () use ($move, $workspace): void {
            $workspace->forceFill([
                'vault_store_id' => $move->to_store_id,
                'vault_prefix' => $move->to_prefix,
                'storage_state' => 'active',
            ])->save();
            $move->forceFill([
                'status' => VaultMove::COMPLETED,
                'completed_at' => now(),
                'retain_until' => now()->addDays(max(0, (int) config('jotter.vault_stores.move_retain_days', 7))),
                'error' => null,
            ])->save();
        });

        $this->audit->record(AuditEvent::VAULT_MOVE_COMPLETED, $workspace->tenant_id, $workspace->id, $move->actor_id, [
            'move_id' => $move->id,
            'files' => $move->files_copied,
            'bytes' => $move->bytes_copied,
            'extra_destination_files' => $extras ? $copied - $files : 0,
        ]);

        if ($extras) {
            app(\App\Domain\Vault\VaultReindexer::class)->reindex($workspace->fresh());
        }
    }

    private function fail(VaultMove $move, Workspace $workspace, \Throwable $exception): void
    {
        $message = mb_substr($exception->getMessage(), 0, 1000);

        DB::transaction(function () use ($move, $workspace, $message): void {
            $move->forceFill(['status' => VaultMove::FAILED, 'error' => $message])->save();
            // The source was never touched: it stays authoritative and writable.
            $workspace->forceFill(['storage_state' => 'active'])->save();
        });

        try {
            $destination = $this->destinationView($move, $workspace);
            if ($destination->capabilities()->writeBehind) {
                // Remote repository: remove only what this move copied, never files someone else pushed.
                $this->deleteCopied($move, $workspace, $destination);
            } else {
                $destination->deleteDirectory('');
            }
        } catch (\Throwable) {
            // Best effort: a leftover partial copy only blocks a retry's "destination empty" check.
        }

        $this->audit->record(AuditEvent::VAULT_MOVE_FAILED, $workspace->tenant_id, $workspace->id, $move->actor_id, [
            'move_id' => $move->id,
            'error' => $message,
        ]);
    }

    private function deleteCopied(VaultMove $move, Workspace $workspace, VaultStore $destination): void
    {
        if ($move->cursor === null) {
            return;
        }
        $source = $this->sourceView($move, $workspace);
        $cursor = null;
        do {
            $page = $source->list('', $cursor, 1000);
            foreach ($page->entries as $entry) {
                if (strcmp($entry->path, (string) $move->cursor) > 0) {
                    return;
                }
                if ($destination->exists($entry->path)) {
                    $destination->delete($entry->path);
                }
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
    }

    private function copyVerified(VaultStore $source, VaultStore $destination, StoreEntry $entry): StoreEntry
    {
        $in = $source->readStream($entry->path);
        $buffer = fopen('php://temp/maxmemory:'.(8 * 1024 * 1024), 'w+b');
        if ($buffer === false) {
            fclose($in);
            throw new \RuntimeException('Unable to open a temporary buffer.');
        }

        try {
            $hash = hash_init('sha256');
            while (! feof($in)) {
                $chunk = fread($in, 1024 * 1024);
                if ($chunk === false) {
                    throw new \RuntimeException("Unable to read [{$entry->path}] from the source.");
                }
                hash_update($hash, $chunk);
                fwrite($buffer, $chunk);
            }
            $expected = hash_final($hash);
            rewind($buffer);

            $written = $destination->writeStream($entry->path, $buffer);
        } finally {
            fclose($in);
            fclose($buffer);
        }

        $check = $destination->readStream($entry->path);
        try {
            $hash = hash_init('sha256');
            hash_update_stream($hash, $check);
            $actual = hash_final($hash);
        } finally {
            fclose($check);
        }

        if (! hash_equals($expected, $actual)) {
            throw new \RuntimeException("Verification failed for [{$entry->path}]: SHA-256 differs after the copy.");
        }

        return $written;
    }

    /**
     * Whether the destination changed after the swap: a note edited (updated_at),
     * a file added or removed (count), or an attachment whose bytes differ from
     * the version recorded when it was copied.
     */
    private function changedSince(VaultMove $move, Workspace $workspace, VaultStore $destination): bool
    {
        if (Note::withTrashed()->where('workspace_id', $workspace->id)->where('updated_at', '>', $move->completed_at)->exists()) {
            return true;
        }

        [$files] = $this->measure($destination);
        if ($files !== $move->files_copied) {
            return true;
        }

        foreach (Attachment::query()->where('workspace_id', $workspace->id)->whereNotNull('remote_version')->cursor() as $attachment) {
            if ($destination->stat((string) $attachment->path)?->version !== $attachment->remote_version) {
                return true;
            }
        }

        return false;
    }

    private function recordRemoteVersion(Workspace $workspace, StoreEntry $entry): void
    {
        // toBase(): recording the store's change token is not an edit, so updated_at stays.
        foreach ([Note::withTrashed(), Attachment::query()] as $query) {
            $query->where('workspace_id', $workspace->id)->where('path', $entry->path)
                ->toBase()->update(['remote_version' => $entry->version]);
        }
    }

    private function resetRemoteVersions(Workspace $workspace): void
    {
        Note::withTrashed()->where('workspace_id', $workspace->id)->toBase()->update(['remote_version' => null]);
        Attachment::query()->where('workspace_id', $workspace->id)->toBase()->update(['remote_version' => null]);
    }

    /**
     * @return array{0: int, 1: int, 2: int} files, bytes, largest file
     */
    private function measure(VaultStore $store): array
    {
        $files = 0;
        $bytes = 0;
        $largest = 0;
        $cursor = null;
        do {
            $page = $store->list('', $cursor, 1000);
            foreach ($page->entries as $entry) {
                $files++;
                $bytes += $entry->size;
                $largest = max($largest, $entry->size);
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);

        return [$files, $bytes, $largest];
    }

    private function probe(VaultStore $store): void
    {
        $path = '.jotter-probe-'.bin2hex(random_bytes(6)).'.txt';
        try {
            $store->write($path, 'probe');
            if ($store->read($path) !== 'probe') {
                throw new VaultMoveRefused('the destination returned different bytes than were written.');
            }
            $store->delete($path);
        } catch (VaultMoveRefused $refused) {
            throw $refused;
        } catch (\Throwable $exception) {
            throw new VaultMoveRefused('the destination is not writable: '.$exception->getMessage());
        }
    }

    /**
     * Moving back to the local folder while it still holds the retained source of
     * an earlier move away from it: that copy is stale, so the earlier move is
     * finalized (its source deleted, audited) before the new copy starts.
     */
    private function releaseStaleLocalCopy(Workspace $workspace, ?string $actorId): void
    {
        $earlier = VaultMove::query()->where('workspace_id', $workspace->id)
            ->where('status', VaultMove::COMPLETED)->whereNull('from_store_id')->get();
        foreach ($earlier as $move) {
            $this->finalize($move, $actorId);
        }
    }

    private function prefixFor(Workspace $workspace, TenantVaultStore $target): string
    {
        // D1: one workspace per repository on GitHub; S3 and local stores use a prefix per workspace.
        if ($target->driver === VaultStoreDrivers::GITHUB) {
            return '';
        }

        $base = trim((string) ($target->settings['prefix'] ?? ''), '/');
        $slug = preg_replace('/[^A-Za-z0-9._-]/', '-', (string) $workspace->slug) ?: 'workspace-'.$workspace->id;

        return ltrim($base.'/'.$slug.'-'.$workspace->id, '/');
    }

    /**
     * @return array{0: VaultStore, 1: VaultStore}
     */
    private function ends(VaultMove $move, Workspace $workspace): array
    {
        return [$this->sourceView($move, $workspace), $this->destinationView($move, $workspace)];
    }

    private function sourceView(VaultMove $move, Workspace $workspace): VaultStore
    {
        return $move->from_store_id === null
            ? $this->resolver->local($workspace)
            : $this->resolver->forStore($move->fromStore()->firstOrFail(), $workspace, (string) $move->from_prefix);
    }

    private function destinationView(VaultMove $move, Workspace $workspace): VaultStore
    {
        return $move->to_store_id === null
            ? $this->resolver->local($workspace)
            : $this->resolver->forStore($move->toStore()->firstOrFail(), $workspace, (string) $move->to_prefix);
    }
}
