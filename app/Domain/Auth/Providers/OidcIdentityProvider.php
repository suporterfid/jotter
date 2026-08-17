<?php

namespace App\Domain\Auth\Providers;

use App\Domain\Auth\AuthenticatedSubject;
use App\Domain\Auth\Contracts\IdentityProvider;
use App\Domain\Auth\Oidc\OidcAuthenticationService;
use Illuminate\Http\Request;

final class OidcIdentityProvider implements IdentityProvider
{
    public function __construct(
        private readonly OidcAuthenticationService $authenticationService,
        private readonly LocalIdentityProvider $localProvider = new LocalIdentityProvider,
    ) {
    }

    public function resolveIdentity(Request $request): ?AuthenticatedSubject
    {
        if (! $request->session()->get('oidc_authenticated', false)) {
            return null;
        }

        $subject = $this->localProvider->resolveIdentity($request);

        if (! $subject) {
            return null;
        }

        return new AuthenticatedSubject(
            subjectId: $subject->subjectId,
            email: $subject->email,
            name: $subject->name,
            isAdmin: $subject->isAdmin,
            user: $subject->user,
            attributes: array_merge($subject->attributes, ['provider' => 'oidc']),
            locale: $subject->locale,
        );
    }

    public function authenticate(array $credentials, Request $request): ?AuthenticatedSubject
    {
        return null;
    }

    public function logout(Request $request): void
    {
        $request->session()->forget('oidc_authenticated');
        $this->localProvider->logout($request);
    }

    public function isAuthorizedForWorkspace(AuthenticatedSubject $subject, int $workspaceId): bool
    {
        return $this->localProvider->isAuthorizedForWorkspace($subject, $workspaceId);
    }

    public function canWriteWorkspace(AuthenticatedSubject $subject, int $workspaceId): bool
    {
        return $this->localProvider->canWriteWorkspace($subject, $workspaceId);
    }

    public function accessibleWorkspaceIds(AuthenticatedSubject $subject): ?array
    {
        return $this->localProvider->accessibleWorkspaceIds($subject);
    }

    public function accessibleTenantIds(AuthenticatedSubject $subject): ?array
    {
        return $this->localProvider->accessibleTenantIds($subject);
    }
}
