<?php

namespace App\Http\Controllers;

use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Auth\Contracts\IdentityProvider;
use App\Domain\Auth\NoteAccess;
use App\Domain\Search\WorkspaceSearch;
use App\Models\Note;
use App\Models\NoteLink;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class McpController extends Controller
{
    public function __construct(
        private readonly IdentityProvider $identityProvider,
        private readonly NoteAccess $noteAccess,
        private readonly AuditRecorder $auditRecorder = new AuditRecorder,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $subject = $this->identityProvider->resolveIdentity($request);

        if (! $subject) {
            $this->auditRecorder->record(
                event: AuditEvent::MCP_AUTH_FAILED,
                metadata: ['ip' => $request->ip()]
            );

            return response()->json([
                'jsonrpc' => '2.0',
                'error' => ['code' => -32001, 'message' => 'Unauthorized machine token'],
                'id' => $request->input('id'),
            ], 401);
        }

        $method = (string) $request->input('method', '');
        $id = $request->input('id');

        $this->auditRecorder->record(
            event: AuditEvent::MCP_METHOD_CALLED,
            actorId: $subject->subjectId,
            metadata: ['method' => $method]
        );

        if ($method === 'initialize') {
            return response()->json([
                'jsonrpc' => '2.0',
                'result' => [
                    'protocolVersion' => '2024-11-05',
                    'capabilities' => ['tools' => new \stdClass, 'resources' => new \stdClass],
                    'serverInfo' => ['name' => 'Jotter MCP Server', 'version' => '1.0.0'],
                ],
                'id' => $id,
            ]);
        }

        if ($method === 'tools/list') {
            return response()->json([
                'jsonrpc' => '2.0',
                'result' => [
                    'tools' => [
                        ['name' => 'list_notes', 'description' => 'List workspace notes'],
                        ['name' => 'read_note', 'description' => 'Read note content'],
                        ['name' => 'search_notes', 'description' => 'Search notes'],
                        ['name' => 'get_backlinks', 'description' => 'Get note backlinks'],
                    ],
                ],
                'id' => $id,
            ]);
        }

        if ($method === 'tools/call') {
            $params = (array) $request->input('params', []);
            $name = (string) ($params['name'] ?? '');
            $args = (array) ($params['arguments'] ?? []);

            $workspaceId = (int) ($args['workspace_id'] ?? 0);
            if (! $workspaceId || ! $this->identityProvider->isAuthorizedForWorkspace($subject, $workspaceId)) {
                return response()->json([
                    'jsonrpc' => '2.0',
                    'error' => ['code' => -32003, 'message' => 'Unauthorized workspace access'],
                    'id' => $id,
                ], 403);
            }

            $workspace = \App\Models\Workspace::find($workspaceId);
            if (! $workspace) {
                return response()->json([
                    'jsonrpc' => '2.0',
                    'error' => ['code' => -32602, 'message' => 'Workspace not found'],
                    'id' => $id,
                ], 404);
            }

            if ($name === 'list_notes') {
                $limit = min((int) ($args['limit'] ?? 50), 100);
                $notes = $this->noteAccess->scopeVisible(Note::query(), $subject, $workspaceId)
                    ->select(['id', 'workspace_id', 'path', 'title', 'updated_at'])
                    ->orderBy('id')
                    ->limit($limit)
                    ->get();

                return response()->json([
                    'jsonrpc' => '2.0',
                    'result' => ['content' => [['type' => 'text', 'text' => json_encode($notes)]]],
                    'id' => $id,
                ]);
            }

            if ($name === 'read_note') {
                $path = (string) ($args['path'] ?? '');
                $note = \App\Models\Note::query()
                    ->where('workspace_id', $workspaceId)
                    ->where('path', $path)
                    ->first();

                if (! $note) {
                    return response()->json([
                        'jsonrpc' => '2.0',
                        'error' => ['code' => -32602, 'message' => 'Note not found'],
                        'id' => $id,
                    ], 404);
                }

                $this->noteAccess->assertView($subject, $note);

                $vaultStorage = new \App\Domain\Vault\VaultStorage();
                $content = $vaultStorage->readContents($workspace, $path);

                return response()->json([
                    'jsonrpc' => '2.0',
                    'result' => ['content' => [['type' => 'text', 'text' => $content ?? '']]],
                    'id' => $id,
                ]);
            }

            if ($name === 'search_notes') {
                $query = (string) ($args['query'] ?? '');
                $results = app(WorkspaceSearch::class)->search($workspace, $query, $subject);

                return response()->json([
                    'jsonrpc' => '2.0',
                    'result' => ['content' => [['type' => 'text', 'text' => json_encode($results)]]],
                    'id' => $id,
                ]);
            }

            if ($name === 'get_backlinks') {
                $target = (string) ($args['target'] ?? '');
                $targetNote = Note::query()
                    ->where('workspace_id', $workspaceId)
                    ->where(function ($query) use ($target): void {
                        $query->where('path', $target)->orWhere('title', $target);
                    })
                    ->first();
                if (! $targetNote) {
                    $backlinks = [];
                } else {
                    $this->noteAccess->assertView($subject, $targetNote);
                    $sourceIds = $this->noteAccess->scopeVisible(Note::query(), $subject, $workspaceId)->pluck('id');
                    $backlinks = NoteLink::query()
                        ->where('target_note_id', $targetNote->id)
                        ->whereIn('source_note_id', $sourceIds)
                        ->with('sourceNote:id,path,title')
                        ->get();
                }

                return response()->json([
                    'jsonrpc' => '2.0',
                    'result' => ['content' => [['type' => 'text', 'text' => json_encode($backlinks)]]],
                    'id' => $id,
                ]);
            }
        }

        return response()->json([
            'jsonrpc' => '2.0',
            'error' => ['code' => -32601, 'message' => 'Method not found'],
            'id' => $id,
        ], 404);
    }
}
