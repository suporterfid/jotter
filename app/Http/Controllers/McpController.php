<?php

namespace App\Http\Controllers;

use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Auth\AuthenticatedSubject;
use App\Domain\Auth\Contracts\IdentityProvider;
use App\Domain\Auth\NoteAccess;
use App\Domain\Mcp\McpInstructions;
use App\Domain\Mcp\McpRateLimiter;
use App\Domain\Mcp\McpToolCatalog;
use App\Domain\Search\WorkspaceSearch;
use App\Domain\Vault\VaultStorage;
use App\Models\Note;
use App\Models\NoteLink;
use App\Models\Workspace;
use App\Support\ReleaseVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;

/**
 * MCP server over Streamable HTTP (JSON responses only, no SSE stream). One
 * POST per JSON-RPC message; notifications are acknowledged with 202.
 * Authentication is a machine token (`Authorization: Bearer jt_mkt_...`) that
 * acts as its user narrowed by its scopes and workspace allowlist (the
 * `mcp:read` scope is enforced by EnforceCredentialAudience); every tool call is
 * authorized per workspace through the IdentityProvider seam. GrandpaSSOn
 * service tokens need `kb:read`. Tools are read-only (see docs/mcp.md).
 *
 * Protocol polish (brain plan PR 2): 401 carries `WWW-Authenticate`, browser
 * `Origin` headers from other hosts are refused, an unsupported
 * `MCP-Protocol-Version` header answers 400, calls are rate limited per token
 * (429 + Retry-After), tools carry `annotations` and are listed by scope, and
 * `initialize` returns the workspace's `_brain/instructions.md`.
 */
final class McpController extends Controller
{
    /** Newest first; the client's requested version is echoed when supported. */
    public const PROTOCOL_VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'];

    public function __construct(
        private readonly IdentityProvider $identityProvider,
        private readonly NoteAccess $noteAccess,
        private readonly McpToolCatalog $tools,
        private readonly McpRateLimiter $rateLimiter,
        private readonly McpInstructions $instructions,
        private readonly AuditRecorder $auditRecorder = new AuditRecorder,
    ) {}

    public function handle(Request $request): JsonResponse|Response
    {
        if (! $this->originAllowed($request)) {
            $this->auditRecorder->record(
                event: AuditEvent::MCP_ORIGIN_REJECTED,
                metadata: ['ip' => $request->ip(), 'origin' => mb_substr((string) $request->header('Origin'), 0, 255)]
            );

            return $this->error($request->input('id'), -32003, 'Origin not allowed', 403);
        }

        $subject = $this->identityProvider->resolveIdentity($request);

        if (! $subject) {
            $this->auditRecorder->record(
                event: AuditEvent::MCP_AUTH_FAILED,
                metadata: ['ip' => $request->ip()]
            );

            return $this->error($request->input('id'), -32001, 'Unauthorized machine token', 401)
                ->header('WWW-Authenticate', $this->bearerChallenge());
        }

        if (($subject->attributes['auth_method'] ?? null) === 'grandpasson_service_token'
            && ! in_array('kb:read', (array) ($subject->attributes['scopes'] ?? []), true)) {
            return $this->error($request->input('id'), -32003, 'Token does not have the required scope (kb:read)', 403);
        }

        $method = (string) $request->input('method', '');
        $id = $request->input('id');

        $headerVersion = $request->header('MCP-Protocol-Version');
        if ($method !== 'initialize' && $headerVersion !== null && $headerVersion !== ''
            && ! in_array($headerVersion, self::PROTOCOL_VERSIONS, true)) {
            return $this->error($id, -32600, 'Unsupported MCP-Protocol-Version: '.mb_substr($headerVersion, 0, 32), 400);
        }

        // JSON-RPC notifications carry no id and expect no body.
        if (str_starts_with($method, 'notifications/')) {
            return response()->noContent(202);
        }

        $retryAfter = $this->rateLimiter->hitCall($subject);
        if ($retryAfter !== null) {
            $this->auditRecorder->record(
                event: AuditEvent::MCP_RATE_LIMITED,
                tenantId: $subject->credential?->tenantId,
                actorId: $subject->subjectId,
                metadata: ($subject->credential?->auditMetadata() ?? []) + ['method' => $method, 'limit' => 'calls_per_minute'],
            );

            return $this->error($id, -32000, 'Rate limit exceeded; retry after '.$retryAfter.' seconds', 429)
                ->header('Retry-After', (string) $retryAfter);
        }

        $metadata = ['method' => $method];
        if ($method === 'tools/call') {
            $metadata['tool'] = mb_substr((string) $request->input('params.name', ''), 0, 64);
        }
        $this->auditRecorder->record(
            event: AuditEvent::MCP_METHOD_CALLED,
            tenantId: $subject->credential?->tenantId,
            actorId: $subject->subjectId,
            metadata: ($subject->credential?->auditMetadata() ?? []) + $metadata,
        );

        return match ($method) {
            'initialize' => $this->initialize($request, $subject, $id),
            'ping' => $this->result($id, new \stdClass),
            'tools/list' => $this->result($id, ['tools' => $this->tools->visibleTo($subject)]),
            'tools/call' => $this->callTool($request, $subject, $id),
            'resources/list' => $this->result($id, ['resources' => []]),
            'prompts/list' => $this->result($id, ['prompts' => []]),
            default => $this->error($id, -32601, 'Method not found', 404),
        };
    }

    private function initialize(Request $request, AuthenticatedSubject $subject, mixed $id): JsonResponse
    {
        $requested = (string) ($request->input('params.protocolVersion') ?? '');
        $version = in_array($requested, self::PROTOCOL_VERSIONS, true) ? $requested : self::PROTOCOL_VERSIONS[0];

        return $this->result($id, [
            'protocolVersion' => $version,
            'capabilities' => ['tools' => ['listChanged' => false], 'resources' => new \stdClass, 'prompts' => new \stdClass],
            'serverInfo' => ['name' => 'Jotter MCP Server', 'version' => ReleaseVersion::current() ?? '1.0.0'],
            'instructions' => $this->instructions->for($subject),
        ]);
    }

    private function callTool(Request $request, AuthenticatedSubject $subject, mixed $id): JsonResponse
    {
        $params = (array) $request->input('params', []);
        $name = (string) ($params['name'] ?? '');
        $args = (array) ($params['arguments'] ?? []);

        if ($name === 'list_workspaces') {
            return $this->toolText($id, $this->accessibleWorkspaces($subject)->map(static fn (Workspace $workspace): array => [
                'id' => $workspace->id,
                'slug' => $workspace->slug,
                'name' => $workspace->name,
            ])->values()->all());
        }

        if (! $this->tools->has($name)) {
            return $this->error($id, -32602, "Unknown tool: {$name}", 404);
        }

        $requiredScope = $this->tools->requiredScope($name);
        if (! $this->tools->subjectHasScope($subject, $requiredScope)) {
            return $this->error($id, -32003, "Tool {$name} requires the {$requiredScope} scope", 403)
                ->header('WWW-Authenticate', sprintf('Bearer error="insufficient_scope", scope="%s"', $requiredScope));
        }

        $workspaceId = (int) ($args['workspace_id'] ?? 0);
        if ($workspaceId === 0) {
            $accessible = $this->accessibleWorkspaces($subject);
            if ($accessible->count() === 1) {
                $workspaceId = (int) $accessible->first()->id;
            } else {
                return $this->toolError($id, 'workspace_id is required: this token can reach '.$accessible->count().' workspaces. Call list_workspaces and pass one of: '
                    .$accessible->map(static fn (Workspace $workspace): string => "{$workspace->id} ({$workspace->slug})")->implode(', '));
            }
        }

        if (! $this->identityProvider->isAuthorizedForWorkspace($subject, $workspaceId)) {
            return $this->error($id, -32003, 'Unauthorized workspace access', 403);
        }

        $workspace = Workspace::query()->find($workspaceId);
        if (! $workspace) {
            return $this->error($id, -32602, 'Workspace not found', 404);
        }

        return match ($name) {
            'list_notes' => $this->listNotes($subject, $workspaceId, $args, $id),
            'read_note' => $this->readNote($subject, $workspace, $args, $id),
            'search_notes' => $this->searchNotes($subject, $workspace, $args, $id),
            default => $this->backlinks($subject, $workspaceId, $args, $id),
        };
    }

    private function listNotes(AuthenticatedSubject $subject, int $workspaceId, array $args, mixed $id): JsonResponse
    {
        $limit = max(1, min((int) ($args['limit'] ?? 50), 100));
        $notes = $this->noteAccess->scopeVisible(Note::query(), $subject, $workspaceId)
            ->select(['id', 'workspace_id', 'path', 'title', 'updated_at'])
            ->orderBy('id')
            ->limit($limit)
            ->get();

        return $this->toolText($id, $notes);
    }

    private function readNote(AuthenticatedSubject $subject, Workspace $workspace, array $args, mixed $id): JsonResponse
    {
        $path = (string) ($args['path'] ?? '');
        $note = Note::query()->where('workspace_id', $workspace->id)->where('path', $path)->first();
        if (! $note) {
            return $this->error($id, -32602, 'Note not found', 404);
        }

        $this->noteAccess->assertView($subject, $note);
        $content = (new VaultStorage)->readContents($workspace, $path);

        return $this->result($id, ['content' => [['type' => 'text', 'text' => $content ?? '']]]);
    }

    private function searchNotes(AuthenticatedSubject $subject, Workspace $workspace, array $args, mixed $id): JsonResponse
    {
        $query = (string) ($args['query'] ?? '');
        $results = app(WorkspaceSearch::class)->search($workspace, $query, $subject);

        return $this->toolText($id, $results);
    }

    private function backlinks(AuthenticatedSubject $subject, int $workspaceId, array $args, mixed $id): JsonResponse
    {
        $target = (string) ($args['target'] ?? '');
        $targetNote = Note::query()
            ->where('workspace_id', $workspaceId)
            ->where(function ($query) use ($target): void {
                $query->where('path', $target)->orWhere('title', $target);
            })
            ->first();

        if (! $targetNote) {
            return $this->toolText($id, []);
        }

        $this->noteAccess->assertView($subject, $targetNote);
        $sourceIds = $this->noteAccess->scopeVisible(Note::query(), $subject, $workspaceId)->pluck('id');
        $backlinks = NoteLink::query()
            ->where('target_note_id', $targetNote->id)
            ->whereIn('source_note_id', $sourceIds)
            ->with('sourceNote:id,path,title')
            ->get();

        return $this->toolText($id, $backlinks);
    }

    /**
     * @return Collection<int, Workspace>
     */
    private function accessibleWorkspaces(AuthenticatedSubject $subject): Collection
    {
        return Workspace::query()->orderBy('id')->get()
            ->filter(fn (Workspace $workspace): bool => $this->identityProvider->isAuthorizedForWorkspace($subject, $workspace->id))
            ->values();
    }

    private function toolText(mixed $id, mixed $payload): JsonResponse
    {
        return $this->result($id, ['content' => [['type' => 'text', 'text' => (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]]]);
    }

    /**
     * Tool-level failure the model can read and recover from (MCP `isError`).
     */
    private function toolError(mixed $id, string $message): JsonResponse
    {
        return $this->result($id, ['content' => [['type' => 'text', 'text' => $message]], 'isError' => true]);
    }

    /**
     * Requests without an Origin header (CLI and server-side clients) pass. A
     * browser Origin must match APP_URL's origin or `jotter.mcp.allowed_origins`.
     */
    private function originAllowed(Request $request): bool
    {
        $origin = $request->header('Origin');
        if ($origin === null || $origin === '') {
            return true;
        }

        $origin = rtrim(strtolower($origin), '/');
        $allowed = array_map(static fn (string $value): string => rtrim(strtolower($value), '/'), (array) config('jotter.mcp.allowed_origins', []));
        $appUrl = parse_url((string) config('app.url'));
        if (isset($appUrl['scheme'], $appUrl['host'])) {
            $allowed[] = strtolower($appUrl['scheme'].'://'.$appUrl['host'].(isset($appUrl['port']) ? ':'.$appUrl['port'] : ''));
        }

        return in_array($origin, $allowed, true);
    }

    /**
     * RFC 6750 challenge; points to the RFC 9728 metadata only when the
     * embedded OAuth server is enabled (decision D4).
     */
    private function bearerChallenge(): string
    {
        $challenge = 'Bearer realm="jotter", scope="mcp:read"';
        if ((bool) config('jotter.oauth.enabled', false)) {
            $challenge .= sprintf(', resource_metadata="%s/.well-known/oauth-protected-resource/api/mcp"', rtrim((string) config('app.url'), '/'));
        }

        return $challenge;
    }

    private function result(mixed $id, mixed $result): JsonResponse
    {
        return response()->json(['jsonrpc' => '2.0', 'result' => $result, 'id' => $id]);
    }

    private function error(mixed $id, int $code, string $message, int $status): JsonResponse
    {
        return response()->json(['jsonrpc' => '2.0', 'error' => ['code' => $code, 'message' => $message], 'id' => $id], $status);
    }
}
