<?php

namespace App\Http\Middleware;

use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Auth\CredentialContext;
use App\Domain\Auth\MachineTokenResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route-level audience for bearer credentials (docs/decisions.md — scoped
 * credentials). Runs on every `/api/*` request and only acts when the request
 * carries a resolved Jotter machine/OAuth token:
 *
 * - `/api/mcp` needs `mcp:read` (tool-level write checks live in the MCP server);
 * - `/api/admin/*`, `/api/auth/*`, `/api/user/*` never accept a credential;
 * - any other route needs `api:read` for safe methods (GET, HEAD, OPTIONS,
 *   PROPFIND) and `api:write` for everything else, WebDAV included.
 *
 * A missing scope answers 403 with `WWW-Authenticate: Bearer
 * error="insufficient_scope"` and is audited with the token id and client.
 */
final class EnforceCredentialAudience
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS', 'PROPFIND'];
    private const NEVER_PREFIXES = ['api/admin', 'api/auth', 'api/user'];

    public function __construct(
        private readonly MachineTokenResolver $tokens = new MachineTokenResolver,
        private readonly AuditRecorder $auditRecorder = new AuditRecorder,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $subject = $this->tokens->subject($request);
        $credential = $subject?->credential;
        if ($credential === null) {
            return $next($request);
        }

        $path = trim($request->path(), '/');
        foreach (self::NEVER_PREFIXES as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return $this->deny($request, $credential, $subject->subjectId, null, 'credential_not_allowed');
            }
        }

        $required = match (true) {
            $path === 'api/mcp' => CredentialContext::SCOPE_MCP_READ,
            in_array(strtoupper($request->method()), self::SAFE_METHODS, true) => CredentialContext::SCOPE_API_READ,
            default => CredentialContext::SCOPE_API_WRITE,
        };

        if (! $credential->hasScope($required)) {
            return $this->deny($request, $credential, $subject->subjectId, $required, 'insufficient_scope');
        }

        return $next($request);
    }

    private function deny(Request $request, CredentialContext $credential, string $actorId, ?string $requiredScope, string $reason): Response
    {
        $this->auditRecorder->record(
            event: AuditEvent::AUTH_FORBIDDEN,
            tenantId: $credential->tenantId,
            actorId: $actorId,
            metadata: $credential->auditMetadata() + [
                'reason' => $reason,
                'required_scope' => $requiredScope,
                'path' => $request->path(),
                'method' => $request->method(),
            ],
        );

        $challenge = $requiredScope !== null
            ? sprintf('Bearer error="insufficient_scope", scope="%s"', $requiredScope)
            : 'Bearer error="insufficient_scope"';

        return response()->json([
            'message' => $requiredScope !== null
                ? __('messages.credential_insufficient_scope', ['scope' => $requiredScope])
                : __('messages.credential_not_allowed'),
        ], 403, ['WWW-Authenticate' => $challenge]);
    }
}
