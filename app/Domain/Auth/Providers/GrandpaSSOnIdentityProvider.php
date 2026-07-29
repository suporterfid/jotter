<?php

namespace App\Domain\Auth\Providers;

use App\Domain\Auth\AuthenticatedSubject;
use App\Domain\Auth\Contracts\IdentityProvider;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use PDO;

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

        if ($authSessId) {
            $subject = $this->resolveFromGrandpaSsonSession((string) $authSessId);
            if ($subject !== null) {
                return $subject;
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

    /**
     * GrandpaSSOn's `sessions`/`users` tables live in the same shared MySQL database as
     * this app's own tables, distinguished only by table-name prefix. Eloquent/DB::table()
     * would silently apply *this app's own* connection prefix (e.g. jt_) and query the
     * wrong tables entirely, so this queries via the raw PDO connection with GrandpaSSOn's
     * configured prefix (jotter.sso.db_prefix / JOTTER_SSO_DB_PREFIX) instead.
     */
    private function resolveFromGrandpaSsonSession(string $authSessId): ?AuthenticatedSubject
    {
        $prefix = (string) config('jotter.sso.db_prefix', '');
        $sessionsTable = $prefix.'sessions';
        $usersTable = $prefix.'users';

        try {
            $pdo = DB::connection()->getPdo();

            $stmt = $pdo->prepare("SELECT * FROM {$sessionsTable} WHERE id = :id AND expires_at > :now LIMIT 1");
            $stmt->execute(['id' => $authSessId, 'now' => time()]);
            $session = $stmt->fetch(PDO::FETCH_OBJ);

            if (! $session || empty($session->user_id)) {
                return null;
            }

            $stmt = $pdo->prepare("SELECT * FROM {$usersTable} WHERE id = :id AND status = 'active' LIMIT 1");
            $stmt->execute(['id' => $session->user_id]);
            $ssoUser = $stmt->fetch(PDO::FETCH_OBJ);

            if (! $ssoUser) {
                return null;
            }

            $user = User::query()->firstOrCreate(
                ['email' => $ssoUser->primary_email],
                [
                    'name' => $ssoUser->display_name ?? 'SSO User',
                    'password' => bcrypt(uniqid('sso_', true)),
                    'is_admin' => false,
                ],
            );

            return new AuthenticatedSubject(
                subjectId: (string) $ssoUser->id,
                email: $ssoUser->primary_email,
                name: $ssoUser->display_name ?? 'SSO User',
                isAdmin: (bool) ($user->is_admin ?? false),
                user: $user,
                attributes: ['sso_provider' => 'grandpasson'],
            );
        } catch (\Throwable) {
            // Missing table / DB error / GrandpaSSOn not deployed alongside this app
            return null;
        }
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
