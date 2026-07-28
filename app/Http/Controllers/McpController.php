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

        return response()->json([
            'jsonrpc' => '2.0',
            'error' => ['code' => -32601, 'message' => 'Method not found'],
            'id' => $id,
        ], 404);
    }
}
