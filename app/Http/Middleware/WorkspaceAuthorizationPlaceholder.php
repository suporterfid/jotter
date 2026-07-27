<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class WorkspaceAuthorizationPlaceholder
{
    public function __construct(
        private readonly AuthorizeWorkspaceAccess $authorizer
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        return $this->authorizer->handle($request, $next);
    }
}
