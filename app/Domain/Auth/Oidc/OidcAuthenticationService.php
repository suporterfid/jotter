<?php

namespace App\Domain\Auth\Oidc;

use App\Domain\Auth\AuthenticatedSubject;
use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditRecorder;
use App\Models\Identity;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class OidcAuthenticationException extends \RuntimeException
{
}

final class OidcAuthenticationService
{
    public function __construct(
        private readonly AuditRecorder $auditRecorder = new AuditRecorder,
    ) {
    }

    public function authorizationUrl(Request $request): string
    {
        return app(OidcClientInterface::class)->authorizationUrl($request);
    }

    public function authenticateCallback(Request $request): AuthenticatedSubject
    {
        $claims = app(OidcClientInterface::class)->authenticateCallback($request);
        $subject = $this->provision($claims);

        if (! $subject->user) {
            throw new OidcAuthenticationException('OIDC user provisioning returned no user.');
        }

        Auth::guard('web')->login($subject->user);
        $request->session()->put('oidc_authenticated', true);
        $request->session()->regenerate();

        return $subject;
    }

    public function provision(OidcClaims $claims): AuthenticatedSubject
    {
        if (! $claims->emailVerified) {
            throw new OidcAuthenticationException('OIDC email is not verified.');
        }

        $identitySubject = rtrim($claims->issuer, '/').'|'.$claims->subject;
        $created = false;

        /** @var User $user */
        $user = DB::transaction(function () use ($claims, $identitySubject, &$created): User {
            $identity = Identity::query()
                ->where('provider', 'oidc')
                ->where('subject_id', $identitySubject)
                ->with('user')
                ->first();

            if ($identity) {
                if (! $identity->user || ! $identity->user->is_active) {
                    throw new OidcAuthenticationException('OIDC identity belongs to an inactive user.');
                }

                $this->synchronizeUser($identity->user, $claims);

                return $identity->user->fresh();
            }

            $user = User::query()->where('email', $claims->email)->first();

            if ($user && ! $user->is_active) {
                throw new OidcAuthenticationException('OIDC email belongs to an inactive user.');
            }

            if (! $user) {
                $created = true;
                $user = User::query()->create([
                    'name' => $claims->name,
                    'email' => $claims->email,
                    'password' => Hash::make(Str::random(64)),
                    'is_admin' => false,
                    'is_active' => true,
                    'locale' => $claims->locale,
                ]);
            } else {
                $this->synchronizeUser($user, $claims);
            }

            Identity::query()->create([
                'user_id' => $user->id,
                'provider' => 'oidc',
                'subject_id' => $identitySubject,
            ]);

            return $user->fresh();
        });

        $this->auditRecorder->record(
            AuditEvent::AUTH_LOGIN_SUCCESS,
            actorId: (string) $user->id,
            metadata: [
                'provider' => 'oidc',
                'provisioned' => $created,
            ],
        );

        return new AuthenticatedSubject(
            subjectId: (string) $user->id,
            email: $user->email,
            name: $user->name,
            isAdmin: (bool) $user->is_admin,
            user: $user,
            attributes: [
                'provider' => 'oidc',
                'identity_subject' => $identitySubject,
            ],
            locale: $user->locale,
        );
    }

    private function synchronizeUser(User $user, OidcClaims $claims): void
    {
        $emailOwner = User::query()
            ->where('email', $claims->email)
            ->whereKeyNot($user->id)
            ->exists();

        if ($emailOwner) {
            throw new OidcAuthenticationException('OIDC email is already linked to another user.');
        }

        $user->forceFill([
            'name' => $claims->name,
            'email' => $claims->email,
            'locale' => $claims->locale,
        ])->save();
    }
}
