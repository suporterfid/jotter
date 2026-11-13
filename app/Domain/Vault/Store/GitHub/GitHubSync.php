<?php

namespace App\Domain\Vault\Store\GitHub;

use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Links\WikilinkProjector;
use App\Domain\Vault\Exceptions\PathTraversalRejected;
use App\Domain\Vault\MarkdownDocument;
use App\Domain\Vault\NoteProjector;
use App\Domain\Vault\NoteRevisions;
use App\Domain\Vault\Store\VaultPathNormalizer;
use App\Models\Note;
use App\Models\TenantVaultStore;
use App\Models\VaultJournalEntry;
use App\Models\VaultRemoteFile;
use App\Models\VaultSyncState;
use App\Models\Workspace;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Normalizer;

/**
 * Brings changes made outside Jotter into the workspace (plan §5.5–§5.6).
 *
 * 1. `GET` the ref with `If-None-Match`: `304` = nothing to do, no quota spent.
 * 2. Head moved → the recursive tree of the new head is compared with
 *    `vault_remote_files` (the head Jotter last saw), which also covers
 *    force-pushes and histories rewritten from scratch. A `compare` call only
 *    tells whether the history diverged, for the audit trail.
 * 3. Each changed file is applied: manifest updated, Markdown projected with a
 *    revision by `github:<login>`, and conflicts with pending Jotter edits
 *    resolved without losing either side (D8).
 *
 * Bounded by a deadline and a file count (the webhook path uses 20 s / 200
 * files): unfinished work leaves `pull_pending`, and the next run resumes,
 * because files already applied match the manifest and are skipped.
 */
class GitHubSync
{
    public function __construct(
        private readonly GitHubStoreFactory $factory,
        private readonly NoteProjector $projector = new NoteProjector,
        private readonly NoteRevisions $revisions = new NoteRevisions,
        private readonly WikilinkProjector $wikilinks = new WikilinkProjector,
        private readonly AuditRecorder $audit = new AuditRecorder,
        private readonly GitHubSyncNotifier $notifier = new GitHubSyncNotifier,
    ) {}

    /**
     * @return array{status: string, applied: int, conflicts: int, head: ?string}
     */
    public function pull(TenantVaultStore $record, ?int $maxFiles = null, ?float $deadline = null, ?string $actorLogin = null): array
    {
        $lock = Cache::lock('vault-sync:'.$record->id, 300);
        if (! $lock->get()) {
            VaultSyncState::for($record)->forceFill(['pull_pending' => true])->save();

            return ['status' => 'locked', 'applied' => 0, 'conflicts' => 0, 'head' => null];
        }

        try {
            return $this->pullLocked($record, $maxFiles, $deadline, $actorLogin);
        } finally {
            $lock->release();
        }
    }

    /**
     * The pull itself; callers hold the store's sync lock (vault:flush pulls
     * before committing when the head moved).
     *
     * @return array{status: string, applied: int, conflicts: int, head: ?string}
     */
    public function pullLocked(TenantVaultStore $record, ?int $maxFiles = null, ?float $deadline = null, ?string $actorLogin = null): array
    {
        $record->refresh();
        $state = VaultSyncState::for($record);
        $client = $this->factory->client($record);
        $branch = $this->factory->branch($record);

        try {
            $ref = $client->ref($branch, $state->initialized ? $state->ref_etag : null);
        } catch (GitHubApiError $error) {
            return $this->failed($record, $state, $error);
        }

        if ($ref->status === GitHubRef::NOT_MODIFIED) {
            $state->forceFill(['last_pull_at' => now(), 'pull_pending' => false])->save();

            return ['status' => 'not_modified', 'applied' => 0, 'conflicts' => 0, 'head' => $state->remote_head];
        }

        if ($ref->status !== GitHubRef::OK) {
            if ($state->remote_head !== null && $ref->status === GitHubRef::MISSING) {
                // The branch Jotter committed to is gone (deleted or renamed).
                return $this->failed($record, $state, new GitHubApiError("The branch [{$branch}] no longer exists in {$record->settings['owner']}/{$record->settings['repo']}.", 404));
            }
            if ($state->remote_head !== null && $ref->status === GitHubRef::EMPTY) {
                return $this->failed($record, $state, new GitHubApiError('The repository was emptied or replaced.', 404));
            }
            // Empty repository, or a branch Jotter will create with its first commit.
            $state->forceFill(['initialized' => true, 'remote_head' => null, 'ref_etag' => null, 'last_pull_at' => now(), 'pull_pending' => false])->save();

            return ['status' => 'empty', 'applied' => 0, 'conflicts' => 0, 'head' => null];
        }

        $head = (string) $ref->sha;
        if ($head === $state->remote_head && $state->initialized) {
            $state->forceFill(['ref_etag' => $ref->etag, 'last_pull_at' => now(), 'pull_pending' => false])->save();

            return ['status' => 'unchanged', 'applied' => 0, 'conflicts' => 0, 'head' => $head];
        }

        try {
            if ($state->remote_head !== null && $state->remote_head !== $head) {
                $this->detectForcePush($record, $client, (string) $state->remote_head, $head);
            }
            $commit = $client->commit($head);
            $tree = $client->tree($commit['tree']);
        } catch (GitHubApiError $error) {
            return $this->failed($record, $state, $error);
        }
        if ($tree['truncated']) {
            return $this->failed($record, $state, new GitHubApiError('The repository tree is too large for the GitHub API (truncated listing).'));
        }

        $workspace = Workspace::query()->where('vault_store_id', $record->id)->first();
        $changes = $this->diff($record, $state, $tree['entries']);

        $applied = 0;
        $conflicts = 0;
        $projectedMarkdown = false;
        $complete = true;
        foreach ($changes as $path => $change) {
            if (($maxFiles !== null && $applied >= $maxFiles) || ($deadline !== null && microtime(true) > $deadline)) {
                $complete = false;
                break;
            }
            try {
                $result = DB::transaction(fn (): array => $this->apply($record, $workspace, (string) $path, $change['sha'], $change['size'], $actorLogin));
            } catch (GitHubApiError $error) {
                return $this->failed($record, $state, $error);
            }
            $applied++;
            $conflicts += $result['conflict'] ? 1 : 0;
            $projectedMarkdown = $projectedMarkdown || $result['markdown'];
        }

        if ($workspace !== null && $projectedMarkdown) {
            $this->wikilinks->resolveWorkspaceLinks($workspace);
        }

        $state->refresh();
        $state->forceFill($complete ? [
            'initialized' => true,
            'remote_head' => $head,
            'ref_etag' => $ref->etag,
            'last_pull_at' => now(),
            'pull_pending' => false,
            'last_error' => null,
        ] : [
            'last_pull_at' => now(),
            'pull_pending' => true,
        ])->save();

        if ($record->status === TenantVaultStore::STATUS_FAILED && $complete) {
            $this->factory->markHealthy($record);
        }

        return ['status' => $complete ? 'pulled' : 'partial', 'applied' => $applied, 'conflicts' => $conflicts, 'head' => $head];
    }

    /**
     * Files whose blob differs between the new tree and the manifest. Ignored
     * entries (.git*, submodules, symlinks, over the size limit, unsafe paths)
     * are recorded for the doctor and never touched.
     *
     * @param  list<array{path: string, mode: string, type: string, sha: string, size: ?int}>  $entries
     * @return array<string, array{sha: ?string, size: int}>
     */
    private function diff(TenantVaultStore $record, VaultSyncState $state, array $entries): array
    {
        $limit = max(1, min(100 * 1024 * 1024, (int) config('jotter.github.max_file_bytes', 10 * 1024 * 1024)));
        $remote = [];
        foreach ($entries as $entry) {
            if ($entry['type'] === 'tree') {
                continue;
            }
            $reason = $this->ignoredBecause($entry, $limit);
            if ($reason !== null) {
                $state->addWarning('ignored:'.$entry['path'], ['type' => 'ignored', 'path' => $entry['path'], 'reason' => $reason]);
                continue;
            }
            $remote[$entry['path']] = ['sha' => $entry['sha'], 'size' => (int) ($entry['size'] ?? 0)];
        }
        $state->save();

        $known = VaultRemoteFile::query()->where('vault_store_id', $record->id)->pluck('blob_sha', 'path')->all();
        $changes = [];
        foreach ($remote as $path => $file) {
            if (($known[$path] ?? null) !== $file['sha']) {
                $changes[$path] = $file;
            }
        }
        foreach (array_keys($known) as $path) {
            if (! isset($remote[$path])) {
                $changes[(string) $path] = ['sha' => null, 'size' => 0];
            }
        }
        ksort($changes, SORT_STRING);

        return $changes;
    }

    /**
     * @param  array{path: string, mode: string, type: string, sha: string, size: ?int}  $entry
     */
    private function ignoredBecause(array $entry, int $limit): ?string
    {
        if ($entry['type'] === 'commit') {
            return 'submodule';
        }
        if ($entry['mode'] === '120000') {
            return 'symlink';
        }
        foreach (explode('/', $entry['path']) as $segment) {
            if (str_starts_with(strtolower($segment), '.git') && $segment !== GitHubVaultStore::MARKER) {
                return 'git metadata';
            }
        }
        if (($entry['size'] ?? 0) > $limit) {
            return 'larger than '.$limit.' bytes';
        }
        if (str_contains($entry['path'], '\\') || str_contains($entry['path'], ':') || strlen($entry['path']) > VaultPathNormalizer::MAX_KEY_BYTES) {
            return 'path not supported';
        }

        return null;
    }

    /**
     * Apply one remote change to the manifest, the journal and the projection.
     *
     * @return array{conflict: bool, markdown: bool}
     */
    private function apply(TenantVaultStore $record, ?Workspace $workspace, string $path, ?string $sha, int $size, ?string $login): array
    {
        $rows = VaultJournalEntry::query()->where('vault_store_id', $record->id)->where('path', $path)
            ->whereNull('flushed_at')->orderBy('id')->get();
        $isNew = $sha !== null && ! VaultRemoteFile::query()->where('vault_store_id', $record->id)->where('path', $path)->exists();

        if ($rows->isEmpty()) {
            if ($sha === null) {
                $this->forgetRemote($record, $path);
                $this->unproject($workspace, $path);

                return ['conflict' => false, 'markdown' => $this->isNote($path)];
            }

            $this->rememberRemote($record, $path, $sha, $size);
            if ($isNew && ($fixed = $this->fixPath($record, $workspace, $path)) !== null) {
                // A path Jotter cannot keep as is (NFD, case twin): renamed in the next commit.
                $this->moveRemoteFile($record, $workspace, $path, $fixed, $sha, $login);

                return ['conflict' => false, 'markdown' => $this->isNote($fixed)];
            }
            $this->project($record, $workspace, $path, $sha, $login);

            return ['conflict' => false, 'markdown' => $this->isNote($path)];
        }

        $latest = $rows->last();
        $base = (string) $rows->first()->base_version;

        if (($sha ?? '') === $base) {
            // GitHub still has what our pending edit was based on.
            $sha === null ? $this->forgetRemote($record, $path) : $this->rememberRemote($record, $path, $sha, $size);

            return ['conflict' => false, 'markdown' => false];
        }

        if ($latest->op === VaultJournalEntry::WRITE && $sha !== null && $latest->git_sha === $sha) {
            // Both sides reached the same bytes: nothing left to send.
            $this->rememberRemote($record, $path, $sha, $size);
            VaultJournalEntry::query()->whereIn('id', $rows->pluck('id'))->delete();
            $this->recordRemoteVersion($workspace, $path, $sha);

            return ['conflict' => false, 'markdown' => false];
        }

        if ($latest->op === VaultJournalEntry::WRITE && $sha !== null) {
            return $this->conflictCopy($record, $workspace, $rows->all(), $path, $sha, $size, $login);
        }

        if ($latest->op === VaultJournalEntry::WRITE && $sha === null) {
            // Jotter edited, GitHub deleted: the edit wins and the file comes back.
            $this->forgetRemote($record, $path);
            VaultJournalEntry::query()->whereIn('id', $rows->pluck('id'))->update(['base_version' => '']);
            $this->notifyNote($workspace, $path, (string) $latest->actor_id, 'restored_after_remote_delete', __('messages.github_sync_restored', ['path' => $path]));
            $this->audit($record, $workspace, 'edit_kept_over_remote_delete', $path, $login);

            return ['conflict' => true, 'markdown' => false];
        }

        if ($latest->op === VaultJournalEntry::DELETE && $sha !== null) {
            // Jotter deleted, GitHub edited: the remote edit wins; the note leaves the trash.
            VaultJournalEntry::query()->whereIn('id', $rows->pluck('id'))->delete();
            $this->rememberRemote($record, $path, $sha, $size);
            $this->untrash($record, $workspace, $path);
            $this->project($record, $workspace, $path, $sha, $login);
            $this->notifyNote($workspace, $path, (string) $latest->actor_id, 'remote_edit_restored_note', __('messages.github_sync_untrashed', ['path' => $path]));
            $this->audit($record, $workspace, 'remote_edit_kept_over_delete', $path, $login);

            return ['conflict' => true, 'markdown' => $this->isNote($path)];
        }

        // Deleted on both sides.
        VaultJournalEntry::query()->whereIn('id', $rows->pluck('id'))->delete();
        $this->forgetRemote($record, $path);

        return ['conflict' => false, 'markdown' => false];
    }

    /**
     * Same file changed on both sides (D8): Jotter's version stays at the
     * original path, GitHub's goes to `Name (GitHub conflict YYYY-MM-DD HHMM).md`.
     *
     * @param  list<VaultJournalEntry>  $rows
     * @return array{conflict: bool, markdown: bool}
     */
    private function conflictCopy(TenantVaultStore $record, ?Workspace $workspace, array $rows, string $path, string $sha, int $size, ?string $login): array
    {
        $store = $this->factory->store($record, $workspace ?? $this->contextWorkspace($record));
        $remoteContents = $store->blob($sha);
        $copy = $this->conflictPath($record, $path);

        $this->rememberRemote($record, $path, $sha, $size);
        VaultJournalEntry::query()->whereIn('id', array_map(static fn (VaultJournalEntry $row): int => (int) $row->id, $rows))->update(['base_version' => $sha]);

        VaultJournalEntry::query()->create([
            'vault_store_id' => $record->id,
            'path' => $copy,
            'op' => VaultJournalEntry::WRITE,
            'content' => $remoteContents,
            'size' => strlen($remoteContents),
            'sha256' => hash('sha256', $remoteContents),
            'git_sha' => $sha,
            'base_version' => '',
            'actor_id' => $this->actorId($login),
            'actor_name' => $login ?? 'GitHub',
        ]);

        if ($workspace !== null && $this->isNote($copy)) {
            $note = $this->projector->project($workspace, $copy, MarkdownDocument::parse($remoteContents, $this->fallbackTitle($copy)));
            $this->revisions->recordRevision($note, $remoteContents, $this->actorId($login));
        }

        $latest = end($rows);
        $this->notifyNote($workspace, $path, $latest instanceof VaultJournalEntry ? (string) $latest->actor_id : null, 'conflict_copy', __('messages.github_sync_conflict', ['path' => $path, 'copy' => $copy]), ['conflict_path' => $copy]);
        $this->audit($record, $workspace, 'conflict_copy', $path, $login, ['conflict_path' => $copy]);

        return ['conflict' => true, 'markdown' => $this->isNote($copy)];
    }

    public function conflictPath(TenantVaultStore $record, string $path): string
    {
        $directory = str_contains($path, '/') ? substr($path, 0, (int) strrpos($path, '/') + 1) : '';
        $file = basename($path);
        $extension = '';
        foreach (['.excalidraw.md', '.md'] as $known) {
            if (str_ends_with(strtolower($file), $known)) {
                $extension = substr($file, -strlen($known));
                break;
            }
        }
        if ($extension === '' && str_contains($file, '.')) {
            $extension = substr($file, (int) strrpos($file, '.'));
        }
        $stem = $extension === '' ? $file : substr($file, 0, -strlen($extension));
        $label = __('messages.github_conflict_label', ['date' => now()->format('Y-m-d Hi')]);

        $candidate = $directory.$stem.' ('.$label.')'.$extension;
        for ($n = 2; $this->pathTaken($record, $candidate); $n++) {
            $candidate = $directory.$stem.' ('.$label.' '.$n.')'.$extension;
        }

        return $candidate;
    }

    /**
     * A remote path Jotter must rename: NFD (macOS) → NFC, or a case twin of an
     * existing path (`Nota.md` / `nota.md`) → `nota (2).md`. Null = keep it.
     */
    private function fixPath(TenantVaultStore $record, ?Workspace $workspace, string $path): ?string
    {
        $target = $path;
        if (class_exists(Normalizer::class)) {
            $nfc = Normalizer::normalize($path, Normalizer::FORM_C);
            if (is_string($nfc)) {
                $target = $nfc;
            }
        }

        try {
            VaultPathNormalizer::normalize($target, nfc: true);
        } catch (PathTraversalRejected) {
            return null;
        }

        $twin = $this->caseTwin($record, $target, $path);
        if ($twin) {
            $target = $this->numbered($record, $target, $path);
        }

        if ($target === $path) {
            return null;
        }

        $state = VaultSyncState::for($record);
        $state->addWarning('renamed:'.$path, ['type' => $twin ? 'case_twin' : 'nfd', 'path' => $path, 'renamed_to' => $target]);
        $state->save();

        return $target;
    }

    private function caseTwin(TenantVaultStore $record, string $candidate, string $self): bool
    {
        $lower = mb_strtolower($candidate);
        foreach ([VaultRemoteFile::query(), VaultJournalEntry::query()->whereNull('flushed_at')->where('op', VaultJournalEntry::WRITE)] as $query) {
            $paths = $query->where('vault_store_id', $record->id)->whereRaw('LOWER(path) = ?', [$lower])->pluck('path')->all();
            foreach ($paths as $existing) {
                if ($existing !== $self && $existing !== $candidate) {
                    return true;
                }
                if ($existing === $candidate && $candidate !== $self) {
                    return true;
                }
            }
        }

        return false;
    }

    private function numbered(TenantVaultStore $record, string $path, string $self): string
    {
        $directory = str_contains($path, '/') ? substr($path, 0, (int) strrpos($path, '/') + 1) : '';
        $file = basename($path);
        $extension = str_contains($file, '.') ? substr($file, (int) strrpos($file, '.')) : '';
        $stem = $extension === '' ? $file : substr($file, 0, -strlen($extension));
        for ($n = 2; ; $n++) {
            $candidate = $directory.$stem.' ('.$n.')'.$extension;
            if (! $this->caseTwin($record, $candidate, $self) && ! $this->pathTaken($record, $candidate)) {
                return $candidate;
            }
        }
    }

    private function moveRemoteFile(TenantVaultStore $record, ?Workspace $workspace, string $from, string $to, string $sha, ?string $login): void
    {
        $store = $this->factory->store($record, $workspace ?? $this->contextWorkspace($record));
        $contents = $store->blob($sha);
        VaultJournalEntry::query()->create([
            'vault_store_id' => $record->id, 'path' => $to, 'op' => VaultJournalEntry::WRITE,
            'content' => $contents, 'size' => strlen($contents), 'sha256' => hash('sha256', $contents),
            'git_sha' => $sha, 'base_version' => '', 'actor_id' => $this->actorId($login), 'actor_name' => 'Jotter',
        ]);
        VaultJournalEntry::query()->create([
            'vault_store_id' => $record->id, 'path' => $from, 'op' => VaultJournalEntry::DELETE,
            'size' => 0, 'base_version' => $sha, 'actor_id' => $this->actorId($login), 'actor_name' => 'Jotter',
        ]);
        if ($workspace !== null && $this->isNote($to)) {
            $note = $this->projector->project($workspace, $to, MarkdownDocument::parse($contents, $this->fallbackTitle($to)));
            $this->revisions->recordRevision($note, $contents, $this->actorId($login));
        }
    }

    private function pathTaken(TenantVaultStore $record, string $path): bool
    {
        return VaultRemoteFile::query()->where('vault_store_id', $record->id)->where('path', $path)->exists()
            || VaultJournalEntry::query()->where('vault_store_id', $record->id)->where('path', $path)->whereNull('flushed_at')->exists();
    }

    private function project(TenantVaultStore $record, ?Workspace $workspace, string $path, string $sha, ?string $login): void
    {
        if ($workspace === null) {
            return;
        }
        if (! $this->isNote($path)) {
            $this->recordRemoteVersion($workspace, $path, $sha);

            return;
        }

        $contents = $this->factory->store($record, $workspace)->blob($sha);
        $note = $this->projector->project($workspace, $path, MarkdownDocument::parse($contents, $this->fallbackTitle($path)));
        $this->revisions->recordRevision($note, $contents, $this->actorId($login));
        $this->recordRemoteVersion($workspace, $path, $sha);
    }

    private function unproject(?Workspace $workspace, string $path): void
    {
        if ($workspace === null) {
            return;
        }
        // Same as vault:reindex for a file that disappeared from the vault.
        Note::query()->where('workspace_id', $workspace->id)->where('path', $path)->get()->each(fn (Note $note) => $note->delete());
    }

    /** The note Jotter trashed comes back to its original path (the remote edit won). */
    private function untrash(TenantVaultStore $record, ?Workspace $workspace, string $path): void
    {
        if ($workspace === null) {
            return;
        }
        $note = Note::onlyTrashed()->where('workspace_id', $workspace->id)->where('original_path', $path)->latest('id')->first();
        if ($note === null) {
            return;
        }

        $trashPath = (string) $note->path;
        $open = VaultJournalEntry::query()->where('vault_store_id', $record->id)->where('path', $trashPath)->whereNull('flushed_at')->orderBy('id')->get();
        if ($open->isNotEmpty() && (string) $open->first()->base_version === '') {
            VaultJournalEntry::query()->whereIn('id', $open->pluck('id'))->delete();
        } elseif (VaultRemoteFile::query()->where('vault_store_id', $record->id)->where('path', $trashPath)->exists()) {
            VaultJournalEntry::query()->create([
                'vault_store_id' => $record->id, 'path' => $trashPath, 'op' => VaultJournalEntry::DELETE, 'size' => 0,
                'base_version' => (string) VaultRemoteFile::query()->where('vault_store_id', $record->id)->where('path', $trashPath)->value('blob_sha'),
                'actor_name' => 'Jotter',
            ]);
        }

        $note->forceFill(['path' => $path, 'original_path' => null])->save();
        $note->restore();
    }

    private function rememberRemote(TenantVaultStore $record, string $path, string $sha, int $size): void
    {
        $updated = VaultRemoteFile::query()->where('vault_store_id', $record->id)->where('path', $path)
            ->update(['blob_sha' => $sha, 'size' => $size, 'updated_at' => now()]);
        if ($updated === 0) {
            VaultRemoteFile::query()->create(['vault_store_id' => $record->id, 'path' => $path, 'blob_sha' => $sha, 'size' => $size]);
        }
    }

    private function forgetRemote(TenantVaultStore $record, string $path): void
    {
        VaultRemoteFile::query()->where('vault_store_id', $record->id)->where('path', $path)->delete();
    }

    private function recordRemoteVersion(?Workspace $workspace, string $path, string $sha): void
    {
        if ($workspace === null) {
            return;
        }
        foreach ([Note::withTrashed(), \App\Models\Attachment::query()] as $query) {
            $query->where('workspace_id', $workspace->id)->where('path', $path)->toBase()->update(['remote_version' => $sha]);
        }
    }

    private function detectForcePush(TenantVaultStore $record, GitHubClient $client, string $from, string $to): void
    {
        try {
            $compare = $client->compare($from, $to);
        } catch (GitHubApiError $error) {
            if ($error->status === 404) {
                // The old head is gone from the repository: history was rewritten.
                $compare = ['status' => 'diverged'];
            } else {
                return;
            }
        }

        if (in_array($compare['status'], ['diverged', 'behind'], true)) {
            $state = VaultSyncState::for($record);
            $state->addWarning('force_push:'.$to, ['type' => 'force_push', 'from' => $from, 'to' => $to]);
            $state->save();
            $this->audit($record, Workspace::query()->where('vault_store_id', $record->id)->first(), 'force_push', null, null, ['from' => $from, 'to' => $to]);
        }
    }

    /**
     * @return array{status: string, applied: int, conflicts: int, head: ?string}
     */
    private function failed(TenantVaultStore $record, VaultSyncState $state, GitHubApiError $error): array
    {
        $state->forceFill(['last_error' => mb_substr($error->getMessage(), 0, 1000), 'pull_pending' => true])->save();
        if ($error->isPermanent()) {
            $this->factory->markFailed($record, $error->getMessage());
        }

        return ['status' => $error->isRateLimited() ? 'rate_limited' : 'failed', 'applied' => 0, 'conflicts' => 0, 'head' => $state->remote_head];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function notifyNote(?Workspace $workspace, string $path, ?string $editor, string $kind, string $title, array $data = []): void
    {
        if ($workspace === null) {
            return;
        }
        $note = Note::withTrashed()->where('workspace_id', $workspace->id)->where('path', $path)->first();
        if ($note !== null) {
            $this->notifier->aboutNote($note, $editor, $kind, $title, $data);
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function audit(TenantVaultStore $record, ?Workspace $workspace, string $action, ?string $path, ?string $login, array $extra = []): void
    {
        $this->audit->record(AuditEvent::VAULT_CONFLICT, (int) $record->tenant_id, $workspace?->id, $this->actorId($login), $extra + [
            'vault_store_id' => $record->id,
            'action' => $action,
            'path' => $path,
        ]);
    }

    private function actorId(?string $login): string
    {
        return $login === null || $login === '' ? 'github' : 'github:'.$login;
    }

    private function isNote(string $path): bool
    {
        $lower = strtolower($path);

        return str_ends_with($lower, '.md') && ! str_ends_with($lower, '.excalidraw.md')
            && ! str_starts_with($path, '.trash/');
    }

    private function contextWorkspace(TenantVaultStore $record): Workspace
    {
        return new Workspace(['tenant_id' => $record->tenant_id, 'slug' => 'github-sync', 'name' => 'github-sync']);
    }

    private function fallbackTitle(string $path): string
    {
        $base = basename($path);
        if (str_ends_with(strtolower($base), '.md')) {
            $base = substr($base, 0, -3);
        }

        return $base === '' ? 'Untitled' : $base;
    }
}
