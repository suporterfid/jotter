<?php

namespace App\Http\Middleware;

use App\Domain\Auth\Contracts\IdentityProvider;
use App\Models\AuditLog;
use App\Models\Workspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AuthorizeWorkspaceAccess
{
    public function __construct(
        private readonly IdentityProvider $identityProvider
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (config('jotter.auth_bypass', false)) {
            return $next($request);
        }

        $subject = $this->identityProvider->resolveIdentity($request);

        if (! $subject) {
            AuditLog::create([
                'actor_subject_id' => null,
                'event' => 'auth.rejected',
                'metadata' => [
                    'reason' => 'unauthenticated',
                    'path' => $request->path(),
                    'method' => $request->method(),
                ],
                'ip_address' => $request->ip(),
            ]);

            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $workspaceParam = $request->route('workspace');
        if ($workspaceParam !== null) {
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

            if ($workspaceId && ! $this->identityProvider->isAuthorizedForWorkspace($subject, $workspaceId)) {
                AuditLog::create([
                    'tenant_id' => $tenantId,
                    'workspace_id' => $workspaceId,
                    'actor_subject_id' => $subject->subjectId,
                    'event' => 'auth.rejected',
                    'metadata' => [
                        'reason' => 'unauthorized_workspace',
                        'path' => $request->path(),
                        'method' => $request->method(),
                    ],
                    'ip_address' => $request->ip(),
                ]);

                return response()->json(['message' => 'Forbidden workspace access.'], 403);
            }
        }

        $request->attributes->set('authenticated_subject', $subject);

        return $next($request);
    }
}
