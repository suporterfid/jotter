<?php

namespace App\Http\Controllers;

use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Auth\AuthenticatedSubject;
use App\Domain\Auth\Contracts\IdentityProvider;
use App\Domain\Auth\NoteAccess;
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
 * acts as its user; every tool call is authorized per workspace through the
 * IdentityProvider seam. Tools are read-only (see docs/mcp.md).
 */
final class McpController extends Controller
{
    /** Newest first; the client's requested version is echoed when supported. */
    public const PROTOCOL_VERSIONS = ['2025-06-18', '2025-03-26', '2024-11-05'];

    public function __construct(
        private readonly IdentityProvider $identityProvider,
        private readonly NoteAccess $noteAccess,
        private readonly AuditRecorder $auditRecorder = new AuditRecorder,
    ) {}

    public function handle(Request $request): JsonResponse|Response
    {
        $subject = $this->identityProvider->resolveIdentity($request);

        if (! $subject) {
            $this->auditRecorder->record(
                event: AuditEvent::MCP_AUTH_FAILED,
                metadata: ['ip' => $request->ip()]
            );

            return $this->error($request->input('id'), -32001, 'Unauthorized machine token', 401);
        }

        $method = (string) $request->input('method', '');
        $id = $request->input('id');

        // JSON-RPC notifications carry no id and expect no body.
        if (str_starts_with($method, 'notifications/')) {
            return response()->noContent(202);
        }

        $this->auditRecorder->record(
            event: AuditEvent::MCP_METHOD_CALLED,
            actorId: $subject->subjectId,
            metadata: ['method' => $method]
        );

        return match ($method) {
            'initialize' => $this->initialize($request, $id),
            'ping' => $this->result($id, new \stdClass),
            'tools/list' => $this->result($id, ['tools' => $this->toolDefinitions()]),
            'tools/call' => $this->callTool($request, $subject, $id),
            'resources/list' => $this->result($id, ['resources' => []]),
            'prompts/list' => $this->result($id, ['prompts' => []]),
            default => $this->error($id, -32601, 'Method not found', 404),
        };
    }

    private function initialize(Request $request, mixed $id): JsonResponse
    {
        $requested = (string) ($request->input('params.protocolVersion') ?? '');
        $version = in_array($requested, self::PROTOCOL_VERSIONS, true) ? $requested : self::PROTOCOL_VERSIONS[0];

        return $this->result($id, [
            'protocolVersion' => $version,
            'capabilities' => ['tools' => ['listChanged' => false], 'resources' => new \stdClass, 'prompts' => new \stdClass],
            'serverInfo' => ['name' => 'Jotter MCP Server', 'version' => ReleaseVersion::current() ?? '1.0.0'],
            'instructions' => 'Read-only access to Jotter Markdown notes. Call list_workspaces first when a tool asks for a workspace_id; when the token can reach exactly one workspace it is used automatically.',
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function toolDefinitions(): array
    {
        $workspaceId = [
            'type' => 'integer',
            'description' => 'Workspace id. Optional when the token can access exactly one workspace; see list_workspaces.',
        ];

        return [
            [
                'name' => 'list_workspaces',
                'description' => 'List the workspaces this token can read (id, slug, name).',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass, 'additionalProperties' => false],
            ],
            [
                'name' => 'list_notes',
                'description' => 'List notes (id, path, title, updated_at) in a workspace, ordered by id.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'workspace_id' => $workspaceId,
                        'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 50],
                    ],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'read_note',
                'description' => 'Read the canonical Markdown of one note by its vault path (for example "guides/setup.md").',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['workspace_id' => $workspaceId, 'path' => ['type' => 'string', 'description' => 'Note path relative to the vault root.']],
                    'required' => ['path'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'search_notes',
                'description' => 'Full-text search over titles and content; returns ranked results with snippets.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['workspace_id' => $workspaceId, 'query' => ['type' => 'string', 'description' => 'Search terms.']],
                    'required' => ['query'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'get_backlinks',
                'description' => 'Notes that link to a target note, identified by path or title.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['workspace_id' => $workspaceId, 'target' => ['type' => 'string', 'description' => 'Target note path or title.']],
                    'required' => ['target'],
                    'additionalProperties' => false,
                ],
            ],
        ];
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

        if (! in_array($name, ['list_notes', 'read_note', 'search_notes', 'get_backlinks'], true)) {
            return $this->error($id, -32602, "Unknown tool: {$name}", 404);
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

    private function result(mixed $id, mixed $result): JsonResponse
    {
        return response()->json(['jsonrpc' => '2.0', 'result' => $result, 'id' => $id]);
    }

    private function error(mixed $id, int $code, string $message, int $status): JsonResponse
    {
        return response()->json(['jsonrpc' => '2.0', 'error' => ['code' => $code, 'message' => $message], 'id' => $id], $status);
    }
}
