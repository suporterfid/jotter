<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fail-closed PR5 seam for the authorization middleware that PR7 will replace
 * with LocalIdentityProvider-backed workspace authorization.
 *
 * TODO(spec: PR7): require an authenticated identity and enforce workspace membership here.
 */
final class WorkspaceAuthorizationPlaceholder
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort(401, 'Authentication is not configured yet.');
    }
}
