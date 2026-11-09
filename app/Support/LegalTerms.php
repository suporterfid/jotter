<?php

namespace App\Support;

use App\Models\User;

/**
 * Versioned acceptance of the Terms of Use / Privacy Policy (LGPD). The
 * operator publishes the texts (JOTTER_BRAND_TERMS_URL / _PRIVACY_URL) and
 * labels them with JOTTER_TERMS_VERSION. A user whose recorded version differs
 * is asked to accept again. Without a version label nothing is asked, so a
 * self-hosted installation behaves exactly as before.
 */
final class LegalTerms
{
    public static function linksConfigured(): bool
    {
        return (string) config('jotter.brand.terms_url', '') !== ''
            || (string) config('jotter.brand.privacy_url', '') !== '';
    }

    public static function currentVersion(): ?string
    {
        $version = trim((string) config('jotter.legal.terms_version', ''));

        return $version === '' ? null : mb_substr($version, 0, 64);
    }

    public static function acceptanceRequired(?User $user): bool
    {
        $version = self::currentVersion();

        return $user !== null && $version !== null && self::linksConfigured()
            && $user->terms_version !== $version;
    }

    /**
     * @return array{version: ?string, accepted_version: ?string, accepted_at: ?string, acceptance_required: bool}
     */
    public static function statusFor(?User $user): array
    {
        return [
            'version' => self::linksConfigured() ? self::currentVersion() : null,
            'accepted_version' => $user?->terms_version,
            'accepted_at' => $user?->terms_accepted_at?->toIso8601String(),
            'acceptance_required' => self::acceptanceRequired($user),
        ];
    }
}
