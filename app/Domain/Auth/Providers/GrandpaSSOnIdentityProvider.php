<?php

namespace App\Domain\Auth\Providers;

use App\Domain\Auth\AuthenticatedSubject;
use App\Domain\Auth\Contracts\IdentityProvider;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class GrandpaSSOnIdentityProvider implements IdentityProvider
{
    private readonly LocalIdentityProvider $localProvider;

    public function __construct()
    {
        $this->localProvider = new LocalIdentityProvider();
    }

    public function resolveIdentity(Request $request): ?AuthenticatedSubject
    {
        // 1. Check GrandpaSSOn AUTHSESSID session cookie
        $authSessId = $request->cookie('AUTHSESSID') ?? ($_COOKIE['AUTHSESSID'] ?? null);

        if ($authSessId && Schema::hasTable('sessions') && Schema::hasTable('users')) {
            try {
                $session = DB::table('sessions')
                    ->where('id', $authSessId)
                    ->where('expires_at', '>', time())
                    ->first();

                if ($session && ! empty($session->user_id)) {
                    $ssoUser = DB::table('users')
                        ->where('id', $session->user_id)
                        ->where('status', 'active')
                        ->first();

                    if ($ssoUser) {
                        $user = User::query()->firstOrCreate(
                            ['email' => $ssoUser->primary_email],
                            [
                                'name' => $ssoUser->display_name ?? 'SSO User',
                                'password' => bcrypt(uniqid('sso_', true)),
                                'is_admin' => true,
                            ],
                        );

                        return new AuthenticatedSubject(
                            subjectId: (string) $ssoUser->id,
                            email: $ssoUser->primary_email,
                            name: $ssoUser->display_name ?? 'SSO User',
                            isAdmin: (bool) ($user->is_admin ?? true),
                            user: $user,
                            attributes: ['sso_provider' => 'grandpasson'],
                        );
                    }
                }
            } catch (\Throwable) {
                // Ignore missing table / DB error
            }
        }

        // 2. Check active web session if authenticated via login endpoint
        /** @var User|null $user */
        $user = Auth::guard('web')->user();
        if ($user && $request->hasSession() && $request->session()->has('sso_authenticated')) {
            return new AuthenticatedSubject(
                subjectId: (string) $user->id,
                email: $user->email,
                name: $user->name,
                isAdmin: (bool) $user->is_admin,
                user: $user,
            );
        }

        return null;
    }

    public function authenticate(array $credentials, Request $request): ?AuthenticatedSubject
    {
        $subject = $this->localProvider->authenticate($credentials, $request);

        if ($subject && $request->hasSession()) {
            $request->session()->put('sso_authenticated', true);
        }

        return $subject;
    }

    public function logout(Request $request): void
    {
        if ($request->hasSession()) {
            $request->session()->forget('sso_authenticated');
        }

        $this->localProvider->logout($request);
    }

    public function isAuthorizedForWorkspace(AuthenticatedSubject $subject, int $workspaceId): bool
    {
        if ($subject->isAdmin) {
            return true;
        }

        return $this->localProvider->isAuthorizedForWorkspace($subject, $workspaceId);
    }
}
