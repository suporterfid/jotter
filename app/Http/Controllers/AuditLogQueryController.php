<?php

namespace App\Http\Controllers;

use App\Domain\Auth\Contracts\IdentityProvider;
use App\Models\AuditLog;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AuditLogQueryController extends Controller
{
    public function __construct(
        private readonly IdentityProvider $identityProvider
    ) {}

    public function index(Request $request, int $workspaceId): JsonResponse
    {
        $subject = $this->identityProvider->resolveIdentity($request);
        if (! $subject) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (! $this->identityProvider->isAuthorizedForWorkspace($subject, $workspaceId)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $workspace = Workspace::query()->find($workspaceId);
        if (! $workspace) {
            return response()->json(['message' => 'Workspace not found.'], 404);
        }

        $logs = AuditLog::query()
            ->where('tenant_id', $workspace->tenant_id)
            ->where(function ($q) use ($workspaceId) {
                $q->where('workspace_id', $workspaceId)
                    ->orWhereNull('workspace_id');
            })
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'actor_subject_id' => $log->actor_subject_id,
                'event' => $log->event,
                'metadata' => $log->metadata,
                'ip_address' => $log->ip_address,
                'created_at' => $log->created_at?->toIso8601String(),
            ]);

        return response()->json([
            'workspace_id' => $workspaceId,
            'audit_logs' => $logs,
        ]);
    }
}
