<?php

namespace App\Domain\OAuth;

use Illuminate\Http\Request;

/**
 * Remembers, in the browser session, the consent screen a person was sent
 * away from to sign in, so every sign-in path can bring them back:
 *
 * - local password: the SPA follows `?return_to=` (frontend/src/services/oauthReturn.ts);
 * - OIDC: the callback pulls it (OidcController);
 * - GrandpaSSOn: the broker always returns to APP_URL, so the SPA shell route
 *   pulls it once the AUTHSESSID session resolves (SpaShellController).
 *
 * Only same-origin `/oauth/authorize?…` paths are kept, so the value can never
 * become an open redirect, and it expires after ten minutes so a later, unrelated
 * visit to `/` does not land on an old consent screen.
 */
final class OAuthLoginReturn
{
    public const SESSION_KEY = 'oauth.return_to';
    private const TTL_SECONDS = 600;

    public static function isConsentPath(string $path): bool
    {
        return str_starts_with($path, '/oauth/authorize?') && ! str_contains($path, '\\');
    }

    public static function remember(Request $request, string $path): void
    {
        if (! $request->hasSession() || ! self::isConsentPath($path)) {
            return;
        }

        $request->session()->put(self::SESSION_KEY, ['path' => $path, 'expires' => time() + self::TTL_SECONDS]);
    }

    public static function has(Request $request): bool
    {
        return $request->hasSession() && $request->session()->has(self::SESSION_KEY);
    }

    /**
     * The remembered consent path, removed from the session; null when there is
     * none, it expired, or it is not a consent path.
     */
    public static function pull(Request $request): ?string
    {
        if (! $request->hasSession()) {
            return null;
        }

        $value = $request->session()->pull(self::SESSION_KEY);
        if (! is_array($value) || ! is_string($value['path'] ?? null) || ! is_int($value['expires'] ?? null)) {
            return null;
        }
        if ($value['expires'] < time() || ! self::isConsentPath($value['path'])) {
            return null;
        }

        return $value['path'];
    }

    public static function forget(Request $request): void
    {
        if ($request->hasSession()) {
            $request->session()->forget(self::SESSION_KEY);
        }
    }
}
