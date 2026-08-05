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
            (new \App\Domain\Audit\AuditRecorder)->record(
                \App\Domain\Audit\AuditEvent::AUTH_UNAUTHORIZED,
                null,
                null,
                null,
                [
                    'reason' => 'unauthenticated',
                    'path' => $request->path(),
                    'method' => $request->method(),
                ]
            );

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
                (new \App\Domain\Audit\AuditRecorder)->record(
                    \App\Domain\Audit\AuditEvent::AUTH_FORBIDDEN,
                    $tenantId,
                    $workspaceId,
                    $subject->subjectId,
                    [
                        'reason' => 'unauthorized_workspace',
                        'path' => $request->path(),
                        'method' => $request->method(),
                    ]
                );

                return response()->json(['message' => 'Forbidden workspace access.'], 403);
            }

            if (($subject->attributes['auth_method'] ?? null) === 'grandpasson_service_token') {
                $requiredScope = in_array($request->method(), ['GET', 'HEAD'], true)
                    ? 'kb:read'
                    : 'kb:write';

                if (! in_array($requiredScope, $subject->attributes['scopes'], true)) {
                    (new \App\Domain\Audit\AuditRecorder)->record(
                        \App\Domain\Audit\AuditEvent::AUTH_FORBIDDEN,
                        $tenantId,
                        $workspaceId,
                        $subject->subjectId,
                        [
                            'reason' => 'insufficient_scope',
                            'required_scope' => $requiredScope,
                            'scopes' => $subject->attributes['scopes'],
                            'path' => $request->path(),
                            'method' => $request->method(),
                        ]
                    );

                    return response()->json(['message' => 'Token does not have the required scope.'], 403);
                }
            }
        }

        $request->attributes->set('authenticated_subject', $subject);

        return $next($request);
    }
}
