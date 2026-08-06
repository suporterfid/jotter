<?php

namespace App\Http\Controllers;

use App\Domain\Auth\Contracts\IdentityProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AuthController extends Controller
{
    public function __construct(
        private readonly IdentityProvider $identityProvider
    ) {}

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['nullable', 'boolean'],
        ]);

        $subject = $this->identityProvider->authenticate($validated, $request);

        if (! $subject) {
            return response()->json([
                'message' => 'Invalid email or password.',
            ], 401);
        }

        return response()->json([
            'data' => [
                'subject_id' => $subject->subjectId,
                'email' => $subject->email,
                'name' => $subject->name,
                'is_admin' => $subject->isAdmin,
            ],
        ]);
    }

    /**
     * Unauthenticated: tells the SPA which auth mode is active and, for
     * grandpasson, where to send the user to sign in. GrandpaSSOn's
     * AUTHSESSID cookie is host-wide (path=/, no explicit domain), so once
     * login completes there, GrandpaSSOnIdentityProvider recognizes the
     * session on the next request here -- no callback/token exchange
     * needed, so redirect_uri just points back at this app's own root and
     * the code/state query params GrandpaSSOn appends go unused.
     */
    public function config(): JsonResponse
    {
        $provider = (string) config('jotter.auth_provider', 'local');

        $ssoLoginUrl = null;
        if ($provider === 'grandpasson') {
            $brokerBaseUrl = config('jotter.sso.broker_base_url');
            $clientId = config('jotter.sso.client_id');

            if ($brokerBaseUrl && $clientId) {
                $ssoLoginUrl = rtrim((string) $brokerBaseUrl, '/').'/login/email?'.http_build_query([
                    'client_id' => $clientId,
                    'redirect_uri' => config('app.url'),
                    'state' => bin2hex(random_bytes(16)),
                ]);
            }
        }

        $versionFile = base_path('VERSION');
        $version = file_exists($versionFile) ? trim(file_get_contents($versionFile)) : null;

        return response()->json([
            'data' => [
                'provider' => $provider,
                'sso_login_url' => $ssoLoginUrl,
                'version' => $version,
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->identityProvider->logout($request);

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $subject = $this->identityProvider->resolveIdentity($request);

        if (! $subject) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        return response()->json([
            'data' => [
                'subject_id' => $subject->subjectId,
                'email' => $subject->email,
                'name' => $subject->name,
                'is_admin' => $subject->isAdmin,
                'locale' => $subject->locale,
            ],
        ]);
    }
}
