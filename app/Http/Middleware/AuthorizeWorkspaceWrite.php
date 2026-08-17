<?php

namespace App\Http\Middleware;

use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Auth\AuthenticatedSubject;
use App\Domain\Auth\Contracts\IdentityProvider;
use App\Models\Workspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AuthorizeWorkspaceWrite
{
    public function __construct(
        private readonly IdentityProvider $identityProvider,
        private readonly AuditRecorder $auditRecorder = new AuditRecorder,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (config('jotter.auth_bypass', false)) {
            return $next($request);
        }

        $subject = $request->attributes->get('authenticated_subject');
        $workspaceParam = $request->route('workspace');
        $workspaceId = null;
        $tenantId = null;

        if ($workspaceParam instanceof Workspace) {
            $workspaceId = $workspaceParam->id;
            $tenantId = $workspaceParam->tenant_id;
        } elseif (is_numeric($workspaceParam)) {
            $workspaceId = (int) $workspaceParam;
            $workspace = Workspace::query()->find($workspaceId);
            $tenantId = $workspace?->tenant_id;
        }

        if (! $subject instanceof AuthenticatedSubject
            || ! $workspaceId
            || ! $this->identityProvider->canWriteWorkspace($subject, $workspaceId)) {
            $this->auditRecorder->record(
                event: AuditEvent::AUTH_FORBIDDEN,
                tenantId: $tenantId,
                workspaceId: $workspaceId,
                actorId: $subject instanceof AuthenticatedSubject ? $subject->subjectId : null,
                metadata: [
                    'reason' => 'insufficient_workspace_role',
                    'path' => $request->path(),
                    'method' => $request->method(),
                ],
            );

            return response()->json(['message' => __('messages.forbidden')], 403);
        }

        return $next($request);
    }
}
