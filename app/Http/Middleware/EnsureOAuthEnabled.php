<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every OAuth route answers 404 unless JOTTER_OAUTH_ENABLED=true (decision D4).
 */
final class EnsureOAuthEnabled
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) config('jotter.oauth.enabled', false)) {
            abort(404);
        }

        return $next($request);
    }
}
