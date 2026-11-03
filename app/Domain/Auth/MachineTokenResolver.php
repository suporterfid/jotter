<?php

namespace App\Domain\Auth;

use App\Models\MachineToken;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Resolves `Authorization: Bearer <token>` against `machine_tokens`, for every
 * identity provider (local, OIDC, GrandpaSSOn), once per request.
 *
 * - A known, active token yields an AuthenticatedSubject carrying its
 *   CredentialContext. The subject never carries the administrator flag
 *   (decision D3): reach is computed by CredentialScope instead.
 * - A Jotter-prefixed token that is unknown, revoked, expired, or whose user is
 *   inactive marks the request as failed, so callers answer 401 instead of
 *   silently falling back to a session cookie.
 * - Any other bearer value (for example a GrandpaSSOn service token) is left
 *   for the configured provider.
 */
final class MachineTokenResolver
{
    /** Prefixes of tokens Jotter itself issues (manual machine token, OAuth access token). */
    public const JOTTER_PREFIXES = ['jt_mkt_', 'jt_oat_'];

    private const SUBJECT_ATTRIBUTE = 'jotter.credential_subject';
    private const FAILED_ATTRIBUTE = 'jotter.credential_failed';
    private const LAST_USED_THROTTLE_SECONDS = 60;

    public function subject(Request $request): ?AuthenticatedSubject
    {
        $this->resolveOnce($request);
        $subject = $request->attributes->get(self::SUBJECT_ATTRIBUTE);

        return $subject instanceof AuthenticatedSubject ? $subject : null;
    }

    /**
     * True when the request presented a Jotter token that did not resolve.
     */
    public function failed(Request $request): bool
    {
        $this->resolveOnce($request);

        return $request->attributes->get(self::FAILED_ATTRIBUTE) === true;
    }

    private function resolveOnce(Request $request): void
    {
        if ($request->attributes->has(self::FAILED_ATTRIBUTE)) {
            return;
        }

        $request->attributes->set(self::FAILED_ATTRIBUTE, false);
        $bearer = $request->bearerToken();
        if ($bearer === null || $bearer === '') {
            return;
        }

        $subject = $this->resolve($bearer);
        if ($subject !== null) {
            $request->attributes->set(self::SUBJECT_ATTRIBUTE, $subject);

            return;
        }

        if ($this->isJotterToken($bearer)) {
            $request->attributes->set(self::FAILED_ATTRIBUTE, true);
        }
    }

    public function resolve(string $plainToken): ?AuthenticatedSubject
    {
        /** @var MachineToken|null $token */
        $token = MachineToken::query()->where('token_hash', MachineToken::hashToken($plainToken))->first();
        if ($token === null || $token->revoked_at !== null) {
            return null;
        }

        if ($token->expires_at !== null && $token->expires_at->isPast()) {
            return null;
        }

        /** @var User|null $user */
        $user = User::query()->find($token->subject_id);
        if ($user === null || $user->is_active === false) {
            return null;
        }

        $this->touch($token);

        return new AuthenticatedSubject(
            subjectId: (string) $user->id,
            email: $user->email,
            name: $user->name,
            isAdmin: false,
            user: $user,
            attributes: ['auth_method' => 'machine_token', 'owner_is_admin' => (bool) $user->is_admin],
            locale: $user->locale,
            credential: CredentialContext::fromToken($token, (bool) $user->is_admin),
        );
    }

    private function isJotterToken(string $bearer): bool
    {
        foreach (self::JOTTER_PREFIXES as $prefix) {
            if (str_starts_with($bearer, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function touch(MachineToken $token): void
    {
        if (! Cache::add('machine_token:last_used:'.$token->id, 1, self::LAST_USED_THROTTLE_SECONDS)) {
            return;
        }

        MachineToken::query()->whereKey($token->id)->toBase()->update(['last_used_at' => now()]);
    }
}
