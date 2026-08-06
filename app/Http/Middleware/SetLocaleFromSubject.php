<?php

namespace App\Http\Middleware;

use App\Domain\Auth\Contracts\IdentityProvider;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

final class SetLocaleFromSubject
{
    public function __construct(
        private readonly IdentityProvider $identityProvider
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $subject = $this->identityProvider->resolveIdentity($request);

        if ($subject !== null) {
            App::setLocale($subject->locale);
        }

        return $next($request);
    }
}
