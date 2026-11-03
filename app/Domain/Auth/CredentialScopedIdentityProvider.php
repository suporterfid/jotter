<?php

namespace App\Domain\Auth;

use App\Domain\Auth\Contracts\IdentityProvider;
use Illuminate\Http\Request;

/**
 * Wraps the configured IdentityProvider (local, OIDC, GrandpaSSOn) so bearer
 * machine/OAuth tokens work with every provider and are always narrowed to
 * their CredentialContext. Session subjects pass straight through to the
 * wrapped provider, unchanged.
 */
final class CredentialScopedIdentityProvider implements IdentityProvider
{
    public function __construct(
        private readonly IdentityProvider $inner,
        private readonly MachineTokenResolver $tokens = new MachineTokenResolver,
        private readonly CredentialScope $scope = new CredentialScope,
    ) {}

    public function inner(): IdentityProvider
    {
        return $this->inner;
    }

    public function resolveIdentity(Request $request): ?AuthenticatedSubject
    {
        $subject = $this->tokens->subject($request);
        if ($subject !== null) {
            return $subject;
        }

        // A Jotter token that did not resolve never falls back to a session.
        if ($this->tokens->failed($request)) {
            return null;
        }

        return $this->inner->resolveIdentity($request);
    }

    public function authenticate(array $credentials, Request $request): ?AuthenticatedSubject
    {
        return $this->inner->authenticate($credentials, $request);
    }

    public function logout(Request $request): void
    {
        $this->inner->logout($request);
    }

    public function isAuthorizedForWorkspace(AuthenticatedSubject $subject, int $workspaceId): bool
    {
        return $subject->credential !== null
            ? $this->scope->allowsWorkspace($subject, $workspaceId)
            : $this->inner->isAuthorizedForWorkspace($subject, $workspaceId);
    }

    public function canWriteWorkspace(AuthenticatedSubject $subject, int $workspaceId): bool
    {
        return $subject->credential !== null
            ? $this->scope->canWrite($subject, $workspaceId)
            : $this->inner->canWriteWorkspace($subject, $workspaceId);
    }

    public function accessibleWorkspaceIds(AuthenticatedSubject $subject): ?array
    {
        return $subject->credential !== null
            ? $this->scope->workspaceIds($subject)
            : $this->inner->accessibleWorkspaceIds($subject);
    }

    public function accessibleTenantIds(AuthenticatedSubject $subject): ?array
    {
        return $subject->credential !== null
            ? [$subject->credential->tenantId]
            : $this->inner->accessibleTenantIds($subject);
    }
}
