<?php

namespace App\Http\Controllers;

use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Auth\Contracts\IdentityProvider;
use App\Domain\Auth\NoteAccess;
use App\Domain\Plan\TenantPlan;
use App\Domain\Vault\Exceptions\PathTraversalRejected;
use App\Domain\Vault\VaultPathGuard;
use App\Domain\Vault\VaultStorage;
use App\Models\Note;
use App\Models\Workspace;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * WebDAV for Obsidian sync clients. Files carry a strong ETag (the SHA-256 of
 * the bytes on disk, the same value as MCP `sha256` and REST `content_hash`),
 * and PUT/DELETE honor If-Match / If-None-Match under the per-note lock the
 * MCP write tools and the REST editor take (#410). Without a conditional
 * header a PUT overwrites, as before.
 */
final class WebDavController extends Controller
{
    private const LOCK_SECONDS = 10;
    private const LOCK_WAIT_SECONDS = 5;

    public function __construct(
        private readonly IdentityProvider $identityProvider,
        private readonly VaultPathGuard $pathGuard,
        private readonly VaultStorage $storage,
        private readonly NoteAccess $noteAccess,
        private readonly AuditRecorder $auditRecorder = new AuditRecorder,
        private readonly TenantPlan $tenantPlan = new TenantPlan,
    ) {}

    public function handle(Request $request, int $workspaceId, ?string $path = null): Response|JsonResponse
    {
        $subject = $this->identityProvider->resolveIdentity($request);
        if (! $subject) {
            return response()->json(['message' => __('messages.unauthenticated')], 401);
        }

        if (! $this->identityProvider->isAuthorizedForWorkspace($subject, $workspaceId)) {
            return response()->json(['message' => __('messages.forbidden')], 403);
        }

        $workspace = Workspace::query()->find($workspaceId);
        if (! $workspace) {
            return response()->json(['message' => __('messages.workspace_not_found')], 404);
        }

        $method = strtoupper($request->method());
        if (in_array($method, ['PUT', 'MKCOL', 'DELETE'], true)
            && ! $this->identityProvider->canWriteWorkspace($subject, $workspaceId)) {
            $this->auditRecorder->record(
                event: AuditEvent::AUTH_FORBIDDEN,
                tenantId: $workspace->tenant_id,
                workspaceId: $workspace->id,
                actorId: $subject->subjectId,
                metadata: [
                    'reason' => 'insufficient_workspace_role',
                    'path' => $request->path(),
                    'method' => $method,
                ],
            );

            return response()->json(['message' => __('messages.forbidden')], 403);
        }

        if (in_array($method, ['PUT', 'MKCOL', 'DELETE'], true)
            && $workspace->tenant !== null
            && ! $this->tenantPlan->allowsWrites($workspace->tenant)) {
            return $this->tenantPlan->denyWrite($workspace->tenant, $request, $workspace->id, $subject->subjectId);
        }

        $targetPath = trim($path ?? '', '/');

        $note = $targetPath === '' ? null : Note::query()
            ->where('workspace_id', $workspaceId)
            ->where('path', $targetPath)
            ->first();
        if ($note !== null) {
            if (in_array($method, ['GET', 'HEAD', 'PROPFIND'], true)) {
                $this->noteAccess->assertView($subject, $note);
            } elseif (in_array($method, ['PUT', 'DELETE'], true)) {
                $this->noteAccess->assertEdit($subject, $note);
            }
        }

        try {
            $fullPath = $targetPath === ''
                ? $this->pathGuard->ensureVaultRoot($workspace)
                : $this->pathGuard->resolve($workspace, $targetPath, mustExist: false, mustBeMarkdown: false);
        } catch (PathTraversalRejected) {
            return response()->json(['message' => __('messages.invalid_path_traversal')], 400);
        }

        return match ($method) {
            'PROPFIND' => $this->handlePropfind($fullPath, $targetPath),
            'GET', 'HEAD' => $this->handleGet($request, $fullPath),
            'PUT' => $this->conditionally($request, $workspace, $targetPath, $fullPath,
                fn (): Response => $this->handlePut($workspace, $targetPath, $request->getContent(), $subject->subjectId)),
            'MKCOL' => $this->handleMkcol($fullPath),
            'DELETE' => $this->conditionally($request, $workspace, $targetPath, $fullPath,
                fn (): Response => $this->handleDelete($workspace, $targetPath)),
            'OPTIONS' => response('', 200, ['Allow' => 'OPTIONS, GET, HEAD, POST, PUT, DELETE, PROPFIND, MKCOL', 'DAV' => '1, 2']),
            default => response("Method {$request->method()} not implemented", 405),
        };
    }

    private function handlePropfind(string $fullPath, string $relativePath): Response
    {
        $isDir = is_dir($fullPath);
        $xml = '<?xml version="1.0" encoding="utf-8"?>'."\n".
            '<d:multistatus xmlns:d="DAV:">'."\n".
            '  <d:response>'."\n".
            '    <d:href>'.htmlspecialchars($relativePath).'</d:href>'."\n".
            '    <d:propstat>'."\n".
            '      <d:prop>'."\n".
            '        <d:resourcetype>'.($isDir ? '<d:collection/>' : '').'</d:resourcetype>'."\n".
            '        <d:displayname>'.htmlspecialchars(basename($relativePath)).'</d:displayname>'."\n".
            (is_file($fullPath) ? '        <d:getetag>'.htmlspecialchars(self::etagOf($fullPath)).'</d:getetag>'."\n" : '').
            '      </d:prop>'."\n".
            '      <d:status>HTTP/1.1 200 OK</d:status>'."\n".
            '    </d:propstat>'."\n".
            '  </d:response>'."\n".
            '</d:multistatus>';

        return response($xml, 207, ['Content-Type' => 'application/xml; charset=utf-8']);
    }

    private function handleGet(Request $request, string $fullPath): Response
    {
        if (! file_exists($fullPath) || is_dir($fullPath)) {
            return response('Not found', 404);
        }

        $contents = (string) file_get_contents($fullPath);
        $etag = self::etag($contents);
        if (self::listMatches((string) $request->header('If-None-Match', ''), $etag, weak: true)) {
            return response('', 304, ['ETag' => $etag]);
        }

        return response($contents, 200, [
            'Content-Type' => 'text/markdown; charset=utf-8',
            'ETag' => $etag,
        ]);
    }

    private function handlePut(Workspace $workspace, string $relativePath, string $content, ?string $actorId = null): Response
    {
        $this->storage->write($workspace, $relativePath, $content, $actorId);

        // 201 even on overwrite, as before #410: sync clients already rely on it.
        return response('', 201, ['ETag' => self::etag($content)]);
    }

    /**
     * Runs a write, first checking If-Match / If-None-Match (RFC 9110 §13.1)
     * against the file on disk. With either header the check and the write
     * happen under the per-note lock; a failed precondition answers 412 and
     * leaves the file untouched.
     *
     * @param callable(): Response $write
     */
    private function conditionally(Request $request, Workspace $workspace, string $relativePath, string $fullPath, callable $write): Response
    {
        $ifMatch = trim((string) $request->header('If-Match', ''));
        $ifNoneMatch = trim((string) $request->header('If-None-Match', ''));

        if ($ifMatch === '' && $ifNoneMatch === '') {
            return $write();
        }

        $guarded = function () use ($ifMatch, $ifNoneMatch, $fullPath, $write): Response {
            clearstatcache(true, $fullPath);
            $current = is_file($fullPath) ? self::etagOf($fullPath) : null;

            // If-Match: "*" needs the file; a tag list needs a strong match.
            if ($ifMatch !== '' && ($current === null || ! self::listMatches($ifMatch, $current, weak: false))) {
                return self::preconditionFailed($current);
            }
            // If-None-Match: "*" means create only; a tag list must not match.
            if ($ifNoneMatch !== '' && $current !== null && self::listMatches($ifNoneMatch, $current, weak: true)) {
                return self::preconditionFailed($current);
            }

            return $write();
        };

        try {
            return Cache::lock('note:'.$workspace->id.':'.sha1($relativePath), self::LOCK_SECONDS)->block(self::LOCK_WAIT_SECONDS, $guarded);
        } catch (LockTimeoutException) {
            return response('The note is being written by someone else; retry in a few seconds.', 423);
        }
    }

    private static function preconditionFailed(?string $current): Response
    {
        return response('The file changed since you read it.', 412, $current !== null ? ['ETag' => $current] : []);
    }

    private static function etag(string $contents): string
    {
        return '"'.hash('sha256', $contents).'"';
    }

    private static function etagOf(string $fullPath): string
    {
        return self::etag((string) file_get_contents($fullPath));
    }

    /**
     * Whether a comma-separated entity-tag list (or "*") matches $etag. Strong
     * comparison treats W/ tags as non-matching; weak comparison ignores W/.
     */
    private static function listMatches(string $header, string $etag, bool $weak): bool
    {
        $header = trim($header);
        if ($header === '') {
            return false;
        }
        if ($header === '*') {
            return true;
        }

        foreach (explode(',', $header) as $candidate) {
            $candidate = trim($candidate);
            if (str_starts_with($candidate, 'W/')) {
                if (! $weak) {
                    continue;
                }
                $candidate = substr($candidate, 2);
            }
            if (hash_equals($etag, $candidate)) {
                return true;
            }
        }

        return false;
    }

    private function handleDelete(Workspace $workspace, string $relativePath): Response
    {
        $this->storage->delete($workspace, $relativePath);

        return response('', 204);
    }

    private function handleMkcol(string $fullPath): Response
    {
        if (! is_dir($fullPath)) {
            @mkdir($fullPath, 0755, true);
        }

        return response('', 201);
    }
}
