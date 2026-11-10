<?php

namespace App\Http\Middleware;

use App\Domain\Auth\TwoFactor\TwoFactorPolicy;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * With JOTTER_2FA_REQUIRED_FOR_ADMINS, an administrator signed in without
 * two-step verification cannot use /api/admin/* until they set it up in
 * "Your profile" (block 4b). Everything else keeps working, so they can.
 */
final class RequireAdminTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $path = trim($request->path(), '/');
        if ($path !== 'api/admin' && ! str_starts_with($path, 'api/admin/')) {
            return $next($request);
        }

        /** @var User|null $user */
        $user = Auth::guard('web')->user();
        if ($user !== null && TwoFactorPolicy::requiredFor($user) && ! $user->hasTwoFactor()) {
            return response()->json([
                'message' => __('messages.two_factor_setup_required'),
                'code' => 'two_factor_setup_required',
            ], 403);
        }

        return $next($request);
    }
}
