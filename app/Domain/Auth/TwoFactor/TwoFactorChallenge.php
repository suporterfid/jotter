<?php

namespace App\Domain\Auth\TwoFactor;

use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditRecorder;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Second step of a password sign-in (block 4b). After the password, the user
 * id waits in the session for at most five minutes; a TOTP code or a
 * recovery code finishes the sign-in. Five wrong codes drop the challenge
 * (the password must be typed again), and a per-account limiter stops a
 * brute force spread across many challenges.
 */
final class TwoFactorChallenge
{
    public const SESSION_KEY = 'two_factor.pending';

    public const TTL_SECONDS = 300;

    public const MAX_ATTEMPTS = 5;

    /** Codes tried per account across challenges, per 15 minutes. */
    public const ACCOUNT_LIMIT = 15;

    public function __construct(private readonly AuditRecorder $audit = new AuditRecorder) {}

    public function start(Request $request, User $user, bool $remember): void
    {
        $request->session()->put(self::SESSION_KEY, [
            'user_id' => $user->id,
            'remember' => $remember,
            'expires_at' => now()->getTimestamp() + self::TTL_SECONDS,
            'attempts' => 0,
        ]);
    }

    public function pending(Request $request): ?User
    {
        $pending = $request->hasSession() ? $request->session()->get(self::SESSION_KEY) : null;
        if (! is_array($pending) || ($pending['expires_at'] ?? 0) < now()->getTimestamp()) {
            return null;
        }
        $user = User::query()->find($pending['user_id'] ?? 0);

        return $user !== null && $user->is_active !== false && $user->hasTwoFactor() ? $user : null;
    }

    /**
     * @return array{status: 'ok'|'invalid'|'expired'|'locked', user?: User, method?: string}
     */
    public function complete(Request $request, ?string $code, ?string $recoveryCode): array
    {
        $user = $this->pending($request);
        if ($user === null) {
            $request->session()->forget(self::SESSION_KEY);

            return ['status' => 'expired'];
        }

        $limiterKey = 'two-factor:'.$user->id;
        if (RateLimiter::tooManyAttempts($limiterKey, self::ACCOUNT_LIMIT)) {
            return ['status' => 'locked'];
        }

        $method = null;
        if ($code !== null && $code !== '') {
            $step = Totp::verify((string) $user->two_factor_secret, $code, $user->two_factor_last_used_step);
            if ($step !== null) {
                $user->forceFill(['two_factor_last_used_step' => $step])->save();
                $method = 'totp';
            }
        } elseif ($recoveryCode !== null && $recoveryCode !== '') {
            $remaining = RecoveryCodes::consume((array) $user->two_factor_recovery_codes, $recoveryCode);
            if ($remaining !== null) {
                $user->forceFill(['two_factor_recovery_codes' => $remaining])->save();
                $method = 'recovery_code';
            }
        }

        if ($method === null) {
            RateLimiter::hit($limiterKey, 900);
            $pending = (array) $request->session()->get(self::SESSION_KEY);
            $pending['attempts'] = (int) ($pending['attempts'] ?? 0) + 1;
            $this->audit->record(AuditEvent::AUTH_LOGIN_FAILURE, null, null, (string) $user->id, ['reason' => 'two_factor_invalid', 'attempt' => $pending['attempts']]);
            if ($pending['attempts'] >= self::MAX_ATTEMPTS) {
                $request->session()->forget(self::SESSION_KEY);

                return ['status' => 'expired'];
            }
            $request->session()->put(self::SESSION_KEY, $pending);

            return ['status' => 'invalid'];
        }

        $remember = (bool) ($request->session()->get(self::SESSION_KEY)['remember'] ?? false);
        $request->session()->forget(self::SESSION_KEY);
        RateLimiter::clear($limiterKey);

        Auth::guard('web')->login($user, $remember);
        $request->session()->regenerate();
        if (config('jotter.auth_provider', 'local') === 'grandpasson') {
            // Same flag GrandpaSSOnIdentityProvider sets after a local password sign-in.
            $request->session()->put('sso_authenticated', true);
        }

        $this->audit->record(AuditEvent::AUTH_LOGIN_SUCCESS, null, null, (string) $user->id, [
            'email' => $user->email,
            'is_admin' => (bool) $user->is_admin,
            'two_factor' => $method,
        ]);
        if ($method === 'recovery_code') {
            $this->audit->record(AuditEvent::TWO_FACTOR_RECOVERY_CODE_USED, null, null, (string) $user->id, ['remaining' => count((array) $user->two_factor_recovery_codes)]);
        }

        return ['status' => 'ok', 'user' => $user, 'method' => $method];
    }
}
