<?php

namespace App\Http\Controllers;

use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Auth\Contracts\IdentityProvider;
use App\Domain\Auth\TwoFactor\TwoFactorChallenge;
use App\Domain\Auth\TwoFactor\TwoFactorPolicy;
use App\Domain\Auth\TwoFactor\TwoFactorRequired;
use App\Domain\Vault\ExternalEmbedPolicy;
use App\Support\Branding;
use App\Support\LegalTerms;
use App\Support\ReleaseVersion;
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

        try {
            $subject = $this->identityProvider->authenticate($validated, $request);
        } catch (TwoFactorRequired) {
            // Password accepted; a code must follow (POST /api/auth/two-factor).
            return response()->json(['data' => ['two_factor_required' => true]], 202);
        }

        if (! $subject) {
            // A sign-up waiting for approval (#390) is told so, but only when the
            // password is right, so the answer reveals nothing to a stranger.
            $pending = \App\Models\User::query()->where('email', \Illuminate\Support\Str::lower(trim($validated['email'])))
                ->where('signup_status', 'pending')->first();
            if ($pending !== null && \Illuminate\Support\Facades\Hash::check($validated['password'], $pending->password)) {
                return response()->json(['message' => __('messages.signup_pending'), 'signup_pending' => true], 403);
            }

            return response()->json([
                'message' => __('messages.invalid_email_or_password'),
            ], 401);
        }

        return response()->json([
            'data' => [
                'subject_id' => $subject->subjectId,
                'email' => $subject->email,
                'name' => $subject->name,
                'is_admin' => $subject->isAdmin,
                'terms' => LegalTerms::statusFor($subject->user),
                'two_factor' => TwoFactorPolicy::statusFor($subject->user),
            ],
        ]);
    }

    /**
     * Second step of a password sign-in: a TOTP code (`code`) or a recovery
     * code (`recovery_code`). 422 for a wrong code; 401 with `two_factor_expired`
     * when the challenge expired or failed five times (sign in again).
     */
    public function twoFactor(Request $request, TwoFactorChallenge $challenge): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['nullable', 'string', 'max:16'],
            'recovery_code' => ['nullable', 'string', 'max:32'],
        ]);

        $result = $challenge->complete($request, $validated['code'] ?? null, $validated['recovery_code'] ?? null);

        return match ($result['status']) {
            'ok' => response()->json(['data' => [
                'subject_id' => (string) $result['user']->id,
                'email' => $result['user']->email,
                'name' => $result['user']->name,
                'is_admin' => (bool) $result['user']->is_admin,
                'locale' => $result['user']->locale,
                'terms' => LegalTerms::statusFor($result['user']),
                'two_factor' => TwoFactorPolicy::statusFor($result['user']),
            ]]),
            'invalid' => response()->json(['message' => __('messages.two_factor_invalid'), 'errors' => ['code' => [__('messages.two_factor_invalid')]]], 422),
            'locked' => response()->json(['message' => __('messages.too_many_attempts')], 429),
            default => response()->json(['message' => __('messages.two_factor_expired'), 'two_factor_expired' => true], 401),
        };
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
        } elseif ($provider === 'oidc' && config('jotter.oidc.configured', false)) {
            $ssoLoginUrl = url('/api/auth/oidc/redirect');
        }

        return response()->json([
            'data' => [
                'provider' => $provider,
                'sso_login_url' => $ssoLoginUrl,
                'signup_enabled' => SignupController::enabled(),
                'version' => ReleaseVersion::current(),
                'external_embed_domains' => ExternalEmbedPolicy::configured()->allowedHosts(),
                'brand' => Branding::configured()->toArray(),
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->identityProvider->logout($request);

        return response()->json([
            'message' => __('messages.logged_out_successfully'),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $subject = $this->identityProvider->resolveIdentity($request);

        if (! $subject) {
            return response()->json([
                'message' => __('messages.unauthenticated'),
            ], 401);
        }

        return response()->json([
            'data' => [
                'subject_id' => $subject->subjectId,
                'email' => $subject->email,
                'name' => $subject->name,
                'is_admin' => $subject->isAdmin,
                'locale' => $subject->locale,
                'terms' => LegalTerms::statusFor($subject->user),
                'two_factor' => TwoFactorPolicy::statusFor($subject->user),
                'deletion_requested_at' => $subject->user?->deletion_requested_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * Records that the signed-in person accepted the current Terms/Privacy
     * version (LGPD). Only a browser session can accept: a machine or OAuth
     * token acts for an agent, not for the person reading the texts.
     */
    public function acceptTerms(Request $request, AuditRecorder $audit): JsonResponse
    {
        $subject = $this->identityProvider->resolveIdentity($request);
        if (! $subject || $subject->user === null) {
            return response()->json(['message' => __('messages.unauthenticated')], 401);
        }
        if ($subject->isCredential()) {
            return response()->json(['message' => __('messages.terms_accept_session_only')], 403);
        }

        $version = LegalTerms::currentVersion();
        if ($version === null || ! LegalTerms::linksConfigured()) {
            return response()->json(['message' => __('messages.terms_not_versioned')], 409);
        }

        $user = $subject->user;
        if ($user->terms_version !== $version) {
            $user->forceFill(['terms_accepted_at' => now(), 'terms_version' => $version])->save();
            $audit->record(
                event: AuditEvent::USER_TERMS_ACCEPTED,
                actorId: $subject->subjectId,
                metadata: ['user_id' => $user->id, 'terms_version' => $version],
            );
        }

        return response()->json(['data' => ['terms' => LegalTerms::statusFor($user->refresh())]]);
    }
}
