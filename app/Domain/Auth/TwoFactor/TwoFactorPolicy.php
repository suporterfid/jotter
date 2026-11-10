<?php

namespace App\Domain\Auth\TwoFactor;

use App\Models\User;

/**
 * Where two-step verification applies (block 4b). It protects password
 * sign-ins, so it is offered with the local provider and with GrandpaSSOn's
 * local fallback; OIDC sign-ins rely on the identity provider's own MFA.
 * JOTTER_2FA_REQUIRED_FOR_ADMINS (default off, so self-hosting is unchanged)
 * makes it mandatory for administrators of a local-provider installation.
 */
final class TwoFactorPolicy
{
    public static function available(): bool
    {
        return in_array((string) config('jotter.auth_provider', 'local'), ['local', 'grandpasson'], true);
    }

    public static function requiredFor(?User $user): bool
    {
        return $user !== null
            && (bool) $user->is_admin
            && (bool) config('jotter.two_factor.required_for_admins', false)
            && (string) config('jotter.auth_provider', 'local') === 'local';
    }

    /**
     * @return array{available: bool, enabled: bool, required: bool, recovery_codes_left: int}
     */
    public static function statusFor(?User $user): array
    {
        return [
            'available' => self::available(),
            'enabled' => $user?->hasTwoFactor() ?? false,
            'required' => self::requiredFor($user),
            'recovery_codes_left' => $user?->hasTwoFactor() ? count((array) $user->two_factor_recovery_codes) : 0,
        ];
    }
}
