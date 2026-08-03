<?php

namespace App\Domain\Auth\Providers;

use App\Domain\Auth\AuthenticatedSubject;
use App\Domain\Auth\Contracts\IdentityProvider;
use App\Models\AuditLog;
use App\Models\Membership;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class LocalIdentityProvider implements IdentityProvider
{
    public function resolveIdentity(Request $request): ?AuthenticatedSubject
    {
        // 1. Check Bearer MachineToken header
        $bearerToken = $request->bearerToken();
        if ($bearerToken) {
            $hash = \App\Models\MachineToken::hashToken($bearerToken);
            $tokenRecord = \App\Models\MachineToken::query()
                ->where('token_hash', $hash)
                ->whereNull('revoked_at')
                ->first();

            if ($tokenRecord) {
                /** @var User|null $user */
                $user = User::find($tokenRecord->subject_id);
                if ($user && $user->is_active !== false) {
                    return new AuthenticatedSubject(
                        subjectId: (string) $user->id,
                        email: $user->email,
                        name: $user->name,
                        isAdmin: (bool) $user->is_admin,
                        user: $user,
                    );
                }
            }
        }

        // 2. Check Session User
        /** @var User|null $user */
        $user = Auth::guard('web')->user() ?? Auth::user();

        if (! $user || $user->is_active === false) {
            return null;
        }

        return new AuthenticatedSubject(
            subjectId: (string) $user->id,
            email: $user->email,
            name: $user->name,
            isAdmin: (bool) $user->is_admin,
            user: $user,
        );
    }

    /**
     * @return array<string>
     */
    private function resolveSubjectIds(AuthenticatedSubject $subject): array
    {
        $subjectIds = array_filter([
            $subject->subjectId,
            (string) $subject->user?->id,
            $subject->email,
        ]);

        if ($subject->user) {
            $identitySubjectIds = $subject->user->identities()->pluck('subject_id')->all();
            $subjectIds = array_unique(array_merge($subjectIds, $identitySubjectIds));
        }

        return $subjectIds;
    }

    public function authenticate(array $credentials, Request $request): ?AuthenticatedSubject
    {
        $email = Str::lower(trim((string) ($credentials['email'] ?? '')));
        $password = (string) ($credentials['password'] ?? '');

        if (! $email || ! $password) {
            (new \App\Domain\Audit\AuditRecorder)->record(
                \App\Domain\Audit\AuditEvent::AUTH_LOGIN_FAILURE,
                null,
                null,
                null,
                [
                    'email' => $email,
                    'reason' => 'missing_credentials',
                ]
            );

            return null;
        }

        /** @var User|null $user */
        $user = User::query()->where('email', $email)->first();

        if (! $user || $user->is_active === false || ! Hash::check($password, $user->password)) {
            (new \App\Domain\Audit\AuditRecorder)->record(
                \App\Domain\Audit\AuditEvent::AUTH_LOGIN_FAILURE,
                null,
                null,
                null,
                [
                    'email' => $email,
                    'reason' => 'invalid_credentials',
                ]
            );

            return null;
        }

        Auth::guard('web')->login($user, (bool) ($credentials['remember'] ?? false));

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        (new \App\Domain\Audit\AuditRecorder)->record(
            \App\Domain\Audit\AuditEvent::AUTH_LOGIN_SUCCESS,
            null,
            null,
            (string) $user->id,
            [
                'email' => $user->email,
                'is_admin' => (bool) $user->is_admin,
            ]
        );

        return new AuthenticatedSubject(
            subjectId: (string) $user->id,
            email: $user->email,
            name: $user->name,
            isAdmin: (bool) $user->is_admin,
            user: $user,
        );
    }

    public function logout(Request $request): void
    {
        /** @var User|null $user */
        $user = Auth::guard('web')->user();

        if ($user) {
            (new \App\Domain\Audit\AuditRecorder)->record(
                \App\Domain\Audit\AuditEvent::AUTH_LOGOUT,
                null,
                null,
                (string) $user->id,
                ['email' => $user->email]
            );
        }

        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
    }

    public function isAuthorizedForWorkspace(AuthenticatedSubject $subject, int $workspaceId): bool
    {
        if ($subject->isAdmin) {
            return true;
        }

        /** @var Workspace|null $workspace */
        $workspace = Workspace::query()->find($workspaceId);

        if (! $workspace) {
            return false;
        }

        $subjectIds = $this->resolveSubjectIds($subject);

        return Membership::query()
            ->where('tenant_id', $workspace->tenant_id)
            ->whereIn('subject_id', $subjectIds)
            ->where(function ($query) use ($workspaceId) {
                $query->where('workspace_id', $workspaceId)
                    ->orWhereNull('workspace_id');
            })
            ->exists();
    }

    public function accessibleWorkspaceIds(AuthenticatedSubject $subject): ?array
    {
        if ($subject->isAdmin) {
            return null;
        }

        $subjectIds = $this->resolveSubjectIds($subject);

        $memberships = Membership::query()
            ->whereIn('subject_id', $subjectIds)
            ->get(['tenant_id', 'workspace_id']);

        $directWorkspaceIds = $memberships->whereNotNull('workspace_id')->pluck('workspace_id')->all();
        $tenantWideTenantIds = $memberships->whereNull('workspace_id')->pluck('tenant_id')->all();

        $tenantWideWorkspaceIds = $tenantWideTenantIds !== []
            ? Workspace::query()->whereIn('tenant_id', $tenantWideTenantIds)->pluck('id')->all()
            : [];

        return array_values(array_unique(array_merge($directWorkspaceIds, $tenantWideWorkspaceIds)));
    }

    public function accessibleTenantIds(AuthenticatedSubject $subject): ?array
    {
        if ($subject->isAdmin) {
            return null;
        }

        $subjectIds = $this->resolveSubjectIds($subject);

        return Membership::query()
            ->whereIn('subject_id', $subjectIds)
            ->distinct()
            ->pluck('tenant_id')
            ->all();
    }
}
