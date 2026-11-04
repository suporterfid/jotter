<?php

namespace App\Http\Controllers;

use App\Domain\Auth\Contracts\IdentityProvider;
use App\Domain\OAuth\OAuthLoginReturn;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Serves the SPA shell at `/`. GrandpaSSOn always sends people back here after
 * sign-in (its redirect_uri is APP_URL), so a consent screen that sent them
 * away to sign in is resumed here once their browser session resolves.
 */
final class SpaShellController extends Controller
{
    public function __invoke(Request $request, IdentityProvider $identityProvider): View|RedirectResponse
    {
        if (OAuthLoginReturn::has($request) && $request->bearerToken() === null) {
            $subject = $identityProvider->resolveIdentity($request);
            if ($subject !== null && $subject->credential === null && $subject->user !== null) {
                $returnTo = OAuthLoginReturn::pull($request);
                if ($returnTo !== null) {
                    return redirect(url($returnTo));
                }
            }
        }

        return view('app');
    }
}
