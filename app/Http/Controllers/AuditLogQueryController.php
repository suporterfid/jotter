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
            return response()->json(['message' => __('messages.unauthenticated')], 401);
        }

        if (! $this->identityProvider->isAuthorizedForWorkspace($subject, $workspaceId)) {
            return response()->json(['message' => __('messages.forbidden')], 403);
        }

        $workspace = Workspace::query()->find($workspaceId);
        if (! $workspace) {
            return response()->json(['message' => __('messages.workspace_not_found')], 404);
        }

        $logs = AuditLog::query()
            ->where(function ($q) use ($workspaceId, $workspace) {
                // Workspace-scoped events: workspace_id alone already
                // unambiguously identifies the tenant, so it's sufficient on
                // its own — requiring a separately-populated tenant_id too
                // silently hid every event whose recorder call omitted it
                // (e.g. WorkspaceEventEmitter::emitMention()).
                $q->where('workspace_id', $workspaceId)
                    ->orWhere(function ($q2) use ($workspace) {
                        $q2->whereNull('workspace_id')->where('tenant_id', $workspace->tenant_id);
                    });
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
