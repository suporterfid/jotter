<?php

namespace App\Http\Controllers;

use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Auth\AuthenticatedSubject;
use App\Domain\Auth\Contracts\IdentityProvider;
use App\Domain\Auth\TwoFactor\RecoveryCodes;
use App\Domain\Auth\TwoFactor\Totp;
use App\Domain\Auth\TwoFactor\TwoFactorPolicy;
use App\Models\User;
use App\Support\Branding;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * "Your profile → Two-step verification" (block 4b). Turning it on, off, or
 * renewing the recovery codes asks for the current password; turning it on
 * only takes effect once a first code proves the authenticator works.
 * Browser sessions only (tokens never reach /api/user/*).
 */
final class UserTwoFactorController extends Controller
{
    private const PASSWORD_TRIES = 5;

    public function __construct(
        private readonly IdentityProvider $identityProvider,
        private readonly AuditRecorder $audit = new AuditRecorder,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $user = $this->user($request);

        return $user === null
            ? response()->json(['message' => __('messages.unauthenticated')], 401)
            : response()->json(['data' => TwoFactorPolicy::statusFor($user)]);
    }

    public function start(Request $request): JsonResponse
    {
        // The provider is public (GET /api/auth/config), so this check may come first.
        if (! TwoFactorPolicy::available()) {
            return response()->json(['message' => __('messages.two_factor_not_available')], 409);
        }
        $user = $this->user($request);
        if ($user === null) {
            return response()->json(['message' => __('messages.unauthenticated')], 401);
        }
        if ($user->hasTwoFactor()) {
            return response()->json(['message' => __('messages.two_factor_already_enabled')], 409);
        }
        if (($refused = $this->checkPassword($request, $user)) !== null) {
            return $refused;
        }

        $secret = Totp::generateSecret();
        $user->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => null, 'two_factor_recovery_codes' => null, 'two_factor_last_used_step' => null])->save();

        return response()->json(['data' => [
            'secret' => $secret,
            'otpauth_uri' => Totp::uri($secret, (string) $user->email, Branding::configured()->name),
        ]]);
    }

    public function confirm(Request $request): JsonResponse
    {
        $user = $this->user($request);
        if ($user === null) {
            return response()->json(['message' => __('messages.unauthenticated')], 401);
        }
        $validated = $request->validate(['code' => ['required', 'string', 'max:16']]);
        if ($user->hasTwoFactor() || (string) $user->two_factor_secret === '') {
            return response()->json(['message' => __('messages.two_factor_not_pending')], 409);
        }

        $step = Totp::verify((string) $user->two_factor_secret, $validated['code']);
        if ($step === null) {
            return response()->json(['message' => __('messages.two_factor_invalid'), 'errors' => ['code' => [__('messages.two_factor_invalid')]]], 422);
        }

        [$codes, $hashes] = RecoveryCodes::generate();
        $user->forceFill(['two_factor_confirmed_at' => now(), 'two_factor_last_used_step' => $step, 'two_factor_recovery_codes' => $hashes])->save();
        $this->audit->record(event: AuditEvent::TWO_FACTOR_ENABLED, actorId: (string) $user->id, metadata: ['user_id' => $user->id]);

        return response()->json(['data' => ['recovery_codes' => $codes, 'two_factor' => TwoFactorPolicy::statusFor($user)]]);
    }

    public function disable(Request $request): JsonResponse
    {
        $user = $this->user($request);
        if ($user === null) {
            return response()->json(['message' => __('messages.unauthenticated')], 401);
        }
        if (($refused = $this->checkPassword($request, $user)) !== null) {
            return $refused;
        }

        $wasEnabled = $user->hasTwoFactor();
        $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null, 'two_factor_last_used_step' => null])->save();
        if ($wasEnabled) {
            $this->audit->record(event: AuditEvent::TWO_FACTOR_DISABLED, actorId: (string) $user->id, metadata: ['user_id' => $user->id]);
        }

        return response()->json(['data' => TwoFactorPolicy::statusFor($user)]);
    }

    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        $user = $this->user($request);
        if ($user === null) {
            return response()->json(['message' => __('messages.unauthenticated')], 401);
        }
        if (! $user->hasTwoFactor()) {
            return response()->json(['message' => __('messages.two_factor_not_pending')], 409);
        }
        if (($refused = $this->checkPassword($request, $user)) !== null) {
            return $refused;
        }

        [$codes, $hashes] = RecoveryCodes::generate();
        $user->forceFill(['two_factor_recovery_codes' => $hashes])->save();
        $this->audit->record(event: AuditEvent::TWO_FACTOR_RECOVERY_CODES_REGENERATED, actorId: (string) $user->id, metadata: ['user_id' => $user->id]);

        return response()->json(['data' => ['recovery_codes' => $codes, 'two_factor' => TwoFactorPolicy::statusFor($user)]]);
    }

    private function user(Request $request): ?User
    {
        $subject = $this->identityProvider->resolveIdentity($request);

        return $subject instanceof AuthenticatedSubject && $subject->user !== null && ! $subject->isCredential() ? $subject->user : null;
    }

    private function checkPassword(Request $request, User $user): ?JsonResponse
    {
        $request->validate(['password' => ['required', 'string']]);
        $key = 'two-factor-password:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, self::PASSWORD_TRIES)) {
            return response()->json(['message' => __('messages.too_many_attempts')], 429, ['Retry-After' => (string) RateLimiter::availableIn($key)]);
        }
        if (! Hash::check((string) $request->input('password'), (string) $user->password)) {
            RateLimiter::hit($key, 900);

            return response()->json(['message' => __('messages.password_incorrect'), 'errors' => ['password' => [__('messages.password_incorrect')]]], 422);
        }
        RateLimiter::clear($key);

        return null;
    }
}
