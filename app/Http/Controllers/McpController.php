<?php

namespace App\Http\Controllers;

use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Auth\Contracts\IdentityProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class McpController extends Controller
{
    public function __construct(
        private readonly IdentityProvider $identityProvider,
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
                $notes = \App\Models\Note::query()
                    ->where('workspace_id', $workspaceId)
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

                $vaultStorage = new \App\Domain\Vault\VaultStorage();
                $content = $vaultStorage->getNoteContent($workspace, $path);

                return response()->json([
                    'jsonrpc' => '2.0',
                    'result' => ['content' => [['type' => 'text', 'text' => $content ?? '']]],
                    'id' => $id,
                ]);
            }

            if ($name === 'search_notes') {
                $query = (string) ($args['query'] ?? '');
                $searcher = new \App\Domain\Vault\WorkspaceSearch();
                $results = $searcher->search($workspace, $query);

                return response()->json([
                    'jsonrpc' => '2.0',
                    'result' => ['content' => [['type' => 'text', 'text' => json_encode($results)]]],
                    'id' => $id,
                ]);
            }

            if ($name === 'get_backlinks') {
                $target = (string) ($args['target'] ?? '');
                $backlinks = \App\Models\NoteLink::query()
                    ->where('workspace_id', $workspaceId)
                    ->where('target_note_title', $target)
                    ->get();

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
