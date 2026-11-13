<?php

namespace App\Domain\Mcp;

use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Auth\AuthenticatedSubject;
use App\Domain\Auth\Contracts\IdentityProvider;
use App\Domain\Auth\CredentialContext;
use App\Domain\Auth\NoteAccess;
use App\Domain\Events\WorkspaceEventEmitter;
use App\Domain\Plan\TenantPlan;
use App\Domain\Vault\Exceptions\PathTraversalRejected;
use App\Domain\Vault\VaultPathGuard;
use App\Domain\Vault\VaultStorage;
use App\Models\Note;
use App\Models\Workspace;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * The MCP write tools (`create_note`, `append_to_note`, `update_note`). Every
 * write follows the same sequence (brain plan PR 3):
 *
 * 1. normalize the path through VaultPathGuard; only `.md`; protected folders refused;
 * 2. `mcp:write` scope, workspace write permission, and note ACL for existing notes;
 * 3. the path and operation must match one of the token's write paths;
 * 4. tenant plan allows writes, content ≤ 256 KB, write rate budget;
 * 5. per-note lock; the current SHA-256 is computed from the file on disk inside it;
 * 6. VaultStorage::write (revision + projections), then note-edited events;
 * 7. audit `mcp.note_written`.
 *
 * Deleting and moving notes is out of scope; the server never stamps front matter.
 */
final class McpNoteWriter
{
    public const MAX_BYTES = 262144;
    private const LOCK_SECONDS = 10;
    private const LOCK_WAIT_SECONDS = 5;
    private const IDEMPOTENCY_TTL = 86400;
    private const MAX_SUFFIX = 50;

    public function __construct(
        private readonly IdentityProvider $identityProvider,
        private readonly NoteAccess $noteAccess,
        private readonly McpRateLimiter $rateLimiter,
        private readonly WorkspaceEventEmitter $events,
        private readonly WritePathPolicy $policy = new WritePathPolicy,
        private readonly MarkdownSectionAppender $appender = new MarkdownSectionAppender,
        private readonly VaultPathGuard $paths = new VaultPathGuard,
        private readonly VaultStorage $storage = new VaultStorage,
        private readonly TenantPlan $tenantPlan = new TenantPlan,
        private readonly AuditRecorder $auditRecorder = new AuditRecorder,
    ) {}

    public function create(AuthenticatedSubject $subject, Workspace $workspace, string $path, string $content, string $ifExists = 'fail'): McpWriteResult
    {
        if (! in_array($ifExists, ['fail', 'suffix'], true)) {
            return McpWriteResult::error('if_exists must be "fail" or "suffix".');
        }

        $guard = $this->guard($subject, $workspace, $path, 'create', $content);
        if ($guard instanceof McpWriteResult) {
            return $guard;
        }

        return $this->locked($workspace, $guard, function (string $relative) use ($subject, $workspace, $content, $ifExists): McpWriteResult {
            $target = $relative;
            if ($this->existsOnDisk($workspace, $target)) {
                if ($ifExists === 'fail') {
                    return McpWriteResult::error("A note already exists at {$relative}. Use if_exists=\"suffix\", append_to_note, or update_note.", [
                        'path' => $relative,
                        'current_sha256' => $this->diskHash($workspace, $relative),
                    ]);
                }

                $target = $this->freeSuffixedPath($subject, $workspace, $relative);
                if ($target === null) {
                    return McpWriteResult::error("No free name found next to {$relative}.");
                }
            }

            return $this->write($subject, $workspace, 'create_note', $target, $content, null, created: true);
        });
    }

    public function append(AuthenticatedSubject $subject, Workspace $workspace, string $path, string $content, ?string $heading = null, ?string $idempotencyKey = null): McpWriteResult
    {
        $guard = $this->guard($subject, $workspace, $path, 'append', $content);
        if ($guard instanceof McpWriteResult) {
            return $guard;
        }

        return $this->locked($workspace, $guard, function (string $relative) use ($subject, $workspace, $content, $heading, $idempotencyKey): McpWriteResult {
            $idempotencyCacheKey = $this->idempotencyCacheKey($subject, $workspace, $relative, $idempotencyKey);
            if ($idempotencyCacheKey !== null && Cache::has($idempotencyCacheKey)) {
                return McpWriteResult::ok([
                    'status' => 'duplicate',
                    'path' => $relative,
                    'sha256' => $this->diskHash($workspace, $relative),
                ]);
            }

            $exists = $this->existsOnDisk($workspace, $relative);
            if ($exists) {
                $denied = $this->assertCanEditExisting($subject, $workspace, $relative);
                if ($denied !== null) {
                    return $denied;
                }
            }

            $before = $exists ? $this->storage->readContents($workspace, $relative) : '';
            $next = $this->appender->append($before, $content, $heading);
            if (strlen($next) > self::MAX_BYTES * 4) {
                return McpWriteResult::error('The note would exceed 1 MB; start a new note instead.');
            }

            $result = $this->write($subject, $workspace, 'append_to_note', $relative, $next, $exists ? hash('sha256', $before) : null, created: ! $exists);
            if ($result->ok && $idempotencyCacheKey !== null) {
                Cache::put($idempotencyCacheKey, true, self::IDEMPOTENCY_TTL);
            }

            return $result;
        });
    }

    public function update(AuthenticatedSubject $subject, Workspace $workspace, string $path, string $content, string $expectedSha256): McpWriteResult
    {
        $expectedSha256 = strtolower(trim($expectedSha256));
        if (preg_match('/^[0-9a-f]{64}$/', $expectedSha256) !== 1) {
            return McpWriteResult::error('expected_sha256 must be the 64-character hex SHA-256 returned by read_note.');
        }

        $guard = $this->guard($subject, $workspace, $path, 'update', $content);
        if ($guard instanceof McpWriteResult) {
            return $guard;
        }

        return $this->locked($workspace, $guard, function (string $relative) use ($subject, $workspace, $content, $expectedSha256): McpWriteResult {
            if (! $this->existsOnDisk($workspace, $relative)) {
                return McpWriteResult::error("No note exists at {$relative}; use create_note.", ['path' => $relative]);
            }

            $denied = $this->assertCanEditExisting($subject, $workspace, $relative);
            if ($denied !== null) {
                return $denied;
            }

            $current = $this->diskHash($workspace, $relative);
            if (! hash_equals((string) $current, $expectedSha256)) {
                return McpWriteResult::error('The note changed since you read it; nothing was written. Read it again and retry with the current hash.', [
                    'path' => $relative,
                    'current_sha256' => $current,
                ]);
            }

            return $this->write($subject, $workspace, 'update_note', $relative, $content, $current, created: false);
        });
    }

    /**
     * Steps 1–4. Returns the normalized relative path, or the error to report.
     */
    private function guard(AuthenticatedSubject $subject, Workspace $workspace, string $path, string $op, string $content): string|McpWriteResult
    {
        try {
            $relative = $this->paths->normalize($workspace, $path);
        } catch (PathTraversalRejected) {
            return McpWriteResult::error("Invalid note path: {$path}. Use a relative path ending in .md.");
        }

        if (WritePathPolicy::isProtected($relative)) {
            return McpWriteResult::error("{$relative} is in a folder agents may never write (_brain/, .trash/, _resources/, _templates/, .obsidian/).");
        }

        $credential = $subject->credential;
        if ($credential === null || ! $credential->hasScope(CredentialContext::SCOPE_MCP_WRITE)) {
            return McpWriteResult::error('This token cannot write (mcp:write scope missing).');
        }

        if (! $this->identityProvider->canWriteWorkspace($subject, $workspace->id)) {
            return McpWriteResult::error('This token cannot write to this workspace.');
        }

        if (! $this->policy->allows($credential, $relative, $op)) {
            $allowed = array_map(
                static fn (array $entry): string => $entry['glob'].' ('.implode(', ', (array) $entry['ops']).')',
                $credential->writePaths,
            );

            return McpWriteResult::error("Operation \"{$op}\" is not allowed on {$relative}. Allowed write paths: ".($allowed === [] ? 'none' : implode('; ', $allowed)).'.', [
                'path' => $relative,
                'client' => $credential->clientSlug,
            ]);
        }

        $tenant = $workspace->tenant;
        if ($tenant !== null && ! $this->tenantPlan->allowsWrites($tenant)) {
            return McpWriteResult::error('This workspace is read-only under its current plan; writing is disabled.');
        }

        if (strlen($content) > self::MAX_BYTES) {
            return McpWriteResult::error('Content exceeds 256 KB per call; split it into several appends.');
        }

        $retryAfter = $this->rateLimiter->hitWrite($subject);
        if ($retryAfter !== null) {
            return McpWriteResult::rateLimited($retryAfter);
        }

        return $relative;
    }

    /**
     * @param callable(string): McpWriteResult $callback
     */
    private function locked(Workspace $workspace, string $relative, callable $callback): McpWriteResult
    {
        $lock = Cache::lock('note:'.$workspace->id.':'.sha1($relative), self::LOCK_SECONDS);

        try {
            return $lock->block(self::LOCK_WAIT_SECONDS, static fn (): McpWriteResult => $callback($relative));
        } catch (LockTimeoutException) {
            return McpWriteResult::error('The note is being written by someone else; retry in a few seconds.', ['path' => $relative]);
        }
    }

    /**
     * Steps 6–7.
     */
    private function write(AuthenticatedSubject $subject, Workspace $workspace, string $tool, string $relative, string $contents, ?string $shaBefore, bool $created): McpWriteResult
    {
        $existing = Note::query()->where('workspace_id', $workspace->id)->where('path', $relative)->first();

        try {
            $note = $this->storage->write($workspace, $relative, $contents, $subject->subjectId);
        } catch (\App\Domain\Quota\QuotaExceeded $full) {
            return McpWriteResult::error($full->getMessage(), ['path' => $relative, 'used_bytes' => $full->usedBytes, 'limit_bytes' => $full->limitBytes]);
        }
        if ($existing !== null) {
            $this->events->emitNoteEdited($note, $subject);
        }

        $shaAfter = hash('sha256', $contents);
        $credential = $subject->credential;
        $this->auditRecorder->record(
            event: AuditEvent::MCP_NOTE_WRITTEN,
            tenantId: $workspace->tenant_id,
            workspaceId: $workspace->id,
            actorId: $subject->subjectId,
            metadata: ($credential?->auditMetadata() ?? []) + [
                'tool' => $tool,
                'path' => $relative,
                'bytes' => strlen($contents),
                'sha256_before' => $shaBefore,
                'sha256_after' => $shaAfter,
            ],
            noteId: $note->id,
        );

        return McpWriteResult::ok([
            'status' => 'written',
            'created' => $created,
            'path' => $relative,
            'note_id' => $note->id,
            'sha256' => $shaAfter,
            'bytes' => strlen($contents),
        ]);
    }

    private function assertCanEditExisting(AuthenticatedSubject $subject, Workspace $workspace, string $relative): ?McpWriteResult
    {
        $note = Note::query()->where('workspace_id', $workspace->id)->where('path', $relative)->first();
        if ($note === null) {
            return null;
        }

        if (! $this->noteAccess->canView($subject, $note)) {
            return McpWriteResult::error("No note exists at {$relative}.");
        }

        return $this->noteAccess->canEdit($subject, $note)
            ? null
            : McpWriteResult::error("This token may not edit {$relative} (note permissions).");
    }

    private function existsOnDisk(Workspace $workspace, string $relative): bool
    {
        return $this->storage->exists($workspace, $relative);
    }

    private function diskHash(Workspace $workspace, string $relative): ?string
    {
        if (! $this->existsOnDisk($workspace, $relative)) {
            return null;
        }

        return hash('sha256', $this->storage->readContents($workspace, $relative));
    }

    private function freeSuffixedPath(AuthenticatedSubject $subject, Workspace $workspace, string $relative): ?string
    {
        $base = substr($relative, 0, -3);
        for ($n = 2; $n <= self::MAX_SUFFIX; $n++) {
            $candidate = "{$base}-{$n}.md";
            if (! $this->existsOnDisk($workspace, $candidate)
                && $subject->credential !== null
                && $this->policy->allows($subject->credential, $candidate, 'create')) {
                return $candidate;
            }
        }

        return null;
    }

    private function idempotencyCacheKey(AuthenticatedSubject $subject, Workspace $workspace, string $relative, ?string $key): ?string
    {
        if ($key === null || trim($key) === '') {
            return null;
        }

        return 'mcp:idem:'.($subject->credential?->tokenId ?? $subject->subjectId).':'.sha1($workspace->id.'|'.$relative.'|'.trim($key));
    }
}
