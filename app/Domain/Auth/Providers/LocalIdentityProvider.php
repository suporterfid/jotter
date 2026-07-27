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
        /** @var User|null $user */
        $user = Auth::guard('web')->user();

        if (! $user) {
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

    public function authenticate(array $credentials, Request $request): ?AuthenticatedSubject
    {
        $email = Str::lower(trim((string) ($credentials['email'] ?? '')));
        $password = (string) ($credentials['password'] ?? '');

        if (! $email || ! $password) {
            AuditLog::create([
                'actor_subject_id' => null,
                'event' => 'auth.login.failed',
                'metadata' => [
                    'email' => $email,
                    'reason' => 'missing_credentials',
                ],
                'ip_address' => $request->ip(),
            ]);

            return null;
        }

        /** @var User|null $user */
        $user = User::query()->where('email', $email)->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            AuditLog::create([
                'actor_subject_id' => null,
                'event' => 'auth.login.failed',
                'metadata' => [
                    'email' => $email,
                    'reason' => 'invalid_credentials',
                ],
                'ip_address' => $request->ip(),
            ]);

            return null;
        }

        Auth::guard('web')->login($user, (bool) ($credentials['remember'] ?? false));

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        AuditLog::create([
            'actor_subject_id' => (string) $user->id,
            'event' => 'auth.login.success',
            'metadata' => [
                'email' => $user->email,
                'is_admin' => (bool) $user->is_admin,
            ],
            'ip_address' => $request->ip(),
        ]);

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
            AuditLog::create([
                'actor_subject_id' => (string) $user->id,
                'event' => 'auth.logout',
                'metadata' => ['email' => $user->email],
                'ip_address' => $request->ip(),
            ]);
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

        $subjectIds = array_filter([
            $subject->subjectId,
            (string) $subject->user?->id,
            $subject->email,
        ]);

        if ($subject->user) {
            $identitySubjectIds = $subject->user->identities()->pluck('subject_id')->all();
            $subjectIds = array_unique(array_merge($subjectIds, $identitySubjectIds));
        }

        return Membership::query()
            ->where('tenant_id', $workspace->tenant_id)
            ->whereIn('subject_id', $subjectIds)
            ->where(function ($query) use ($workspaceId) {
                $query->where('workspace_id', $workspaceId)
                    ->orWhereNull('workspace_id');
            })
            ->exists();
    }
}
