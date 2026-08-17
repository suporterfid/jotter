<?php

namespace App\Domain\Auth\Providers;

use App\Domain\Auth\AuthenticatedSubject;
use App\Domain\Auth\Contracts\IdentityProvider;
use App\Domain\Auth\GrandpaSson\HttpIntrospectionClient;
use App\Domain\Auth\GrandpaSson\IntrospectionClientInterface;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use PDO;

final class GrandpaSSOnIdentityProvider implements IdentityProvider
{
    private readonly LocalIdentityProvider $localProvider;

    public function __construct(
        private readonly IntrospectionClientInterface $introspection = new HttpIntrospectionClient()
    ) {
        $this->localProvider = new LocalIdentityProvider();
    }

    public function resolveIdentity(Request $request): ?AuthenticatedSubject
    {
        // 1. Check Bearer GrandpaSSOn service token (client_credentials flow) —
        // see docs/superpowers/specs/2026-08-05-grandpasson-service-tokens-design.md
        if (config('jotter.grandpasson_resource.inbound_enabled', false)) {
            $bearerToken = $request->bearerToken();
            if ($bearerToken) {
                $subject = $this->resolveFromServiceToken($bearerToken);
                if ($subject !== null) {
                    return $subject;
                }
            }
        }

        // 2. Check GrandpaSSOn AUTHSESSID session cookie
        $authSessId = $request->cookie('AUTHSESSID') ?? ($_COOKIE['AUTHSESSID'] ?? null);

        if ($authSessId) {
            $subject = $this->resolveFromGrandpaSsonSession((string) $authSessId);
            if ($subject !== null) {
                return $subject;
            }
        }

        // 3. Check active web session if authenticated via login endpoint
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
     * A GrandpaSSOn client_credentials service token has no User row — it
     * represents a machine caller scoped to exactly the workspace(s) named
     * in its `aud` claim (see isAuthorizedForWorkspace() below), not a
     * Membership row. attributes['auth_method'] distinguishes this subject
     * type everywhere else in this class and in AuthorizeWorkspaceAccess.
     */
    private function resolveFromServiceToken(string $token): ?AuthenticatedSubject
    {
        $result = $this->introspection->introspect($token);
        if (! $result->active || $result->clientId === null) {
            return null;
        }

        return new AuthenticatedSubject(
            subjectId: "service:{$result->clientId}",
            email: '',
            name: "Service client {$result->clientId}",
            isAdmin: false,
            user: null,
            attributes: [
                'auth_method' => 'grandpasson_service_token',
                'scopes' => $result->scopes,
                'audiences' => $result->audiences,
            ],
        );
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

            $user = User::query()->updateOrCreate(
                ['email' => $ssoUser->primary_email],
                array_merge(
                    ['locale' => $ssoUser->locale ?? 'pt-BR'],
                    User::where('email', $ssoUser->primary_email)->exists() ? [] : [
                        'name' => $ssoUser->display_name ?? 'SSO User',
                        'password' => bcrypt(uniqid('sso_', true)),
                        'is_admin' => false,
                    ],
                ),
            );

            return new AuthenticatedSubject(
                subjectId: (string) $ssoUser->id,
                email: $ssoUser->primary_email,
                name: $ssoUser->display_name ?? 'SSO User',
                isAdmin: (bool) ($user->is_admin ?? false),
                user: $user,
                attributes: ['sso_provider' => 'grandpasson'],
                locale: $ssoUser->locale ?? 'pt-BR',
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
        if ($this->isServiceToken($subject)) {
            return in_array("workspace/{$workspaceId}", $subject->attributes['audiences'], true);
        }

        if ($subject->isAdmin) {
            return true;
        }

        return $this->localProvider->isAuthorizedForWorkspace($subject, $workspaceId);
    }

    public function canWriteWorkspace(AuthenticatedSubject $subject, int $workspaceId): bool
    {
        if ($this->isServiceToken($subject)) {
            return in_array("workspace/{$workspaceId}", $subject->attributes['audiences'], true)
                && in_array('kb:write', $subject->attributes['scopes'], true);
        }

        if ($subject->isAdmin) {
            return true;
        }

        return $this->localProvider->canWriteWorkspace($subject, $workspaceId);
    }

    public function accessibleWorkspaceIds(AuthenticatedSubject $subject): ?array
    {
        if ($this->isServiceToken($subject)) {
            return $this->serviceTokenWorkspaceIds($subject);
        }

        if ($subject->isAdmin) {
            return null;
        }

        return $this->localProvider->accessibleWorkspaceIds($subject);
    }

    public function accessibleTenantIds(AuthenticatedSubject $subject): ?array
    {
        if ($this->isServiceToken($subject)) {
            return Workspace::query()
                ->whereIn('id', $this->serviceTokenWorkspaceIds($subject))
                ->pluck('tenant_id')
                ->unique()
                ->values()
                ->all();
        }

        if ($subject->isAdmin) {
            return null;
        }

        return $this->localProvider->accessibleTenantIds($subject);
    }

    private function isServiceToken(AuthenticatedSubject $subject): bool
    {
        return ($subject->attributes['auth_method'] ?? null) === 'grandpasson_service_token';
    }

    /**
     * @return list<int>
     */
    private function serviceTokenWorkspaceIds(AuthenticatedSubject $subject): array
    {
        $ids = [];
        foreach ($subject->attributes['audiences'] ?? [] as $audience) {
            if (preg_match('/^workspace\/(\d+)$/', $audience, $matches)) {
                $ids[] = (int) $matches[1];
            }
        }

        return $ids;
    }
}
