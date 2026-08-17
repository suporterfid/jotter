<?php

namespace App\Http\Controllers;

use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Auth\Contracts\IdentityProvider;
use App\Domain\Vault\Exceptions\PathTraversalRejected;
use App\Domain\Vault\VaultPathGuard;
use App\Domain\Vault\VaultStorage;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class WebDavController extends Controller
{
    public function __construct(
        private readonly IdentityProvider $identityProvider,
        private readonly VaultPathGuard $pathGuard,
        private readonly VaultStorage $storage,
        private readonly AuditRecorder $auditRecorder = new AuditRecorder,
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

        $targetPath = trim($path ?? '', '/');

        try {
            $fullPath = $targetPath === ''
                ? $this->pathGuard->ensureVaultRoot($workspace)
                : $this->pathGuard->resolve($workspace, $targetPath, mustExist: false, mustBeMarkdown: false);
        } catch (PathTraversalRejected) {
            return response()->json(['message' => __('messages.invalid_path_traversal')], 400);
        }

        return match ($method) {
            'PROPFIND' => $this->handlePropfind($fullPath, $targetPath),
            'GET' => $this->handleGet($fullPath),
            'PUT' => $this->handlePut($workspace, $targetPath, $request->getContent()),
            'MKCOL' => $this->handleMkcol($fullPath),
            'DELETE' => $this->handleDelete($workspace, $targetPath),
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
            '      </d:prop>'."\n".
            '      <d:status>HTTP/1.1 200 OK</d:status>'."\n".
            '    </d:propstat>'."\n".
            '  </d:response>'."\n".
            '</d:multistatus>';

        return response($xml, 207, ['Content-Type' => 'application/xml; charset=utf-8']);
    }

    private function handleGet(string $fullPath): Response
    {
        if (! file_exists($fullPath) || is_dir($fullPath)) {
            return response('Not found', 404);
        }

        return response(file_get_contents($fullPath), 200, [
            'Content-Type' => 'text/markdown; charset=utf-8',
        ]);
    }

    private function handlePut(Workspace $workspace, string $relativePath, string $content): Response
    {
        $this->storage->write($workspace, $relativePath, $content);

        return response('', 201);
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
