<?php

namespace App\Domain\Auth\Contracts;

use App\Domain\Auth\AuthenticatedSubject;
use Illuminate\Http\Request;

interface IdentityProvider
{
    /**
     * Resolve identity/authenticated subject from the incoming request.
     */
    public function resolveIdentity(Request $request): ?AuthenticatedSubject;

    /**
     * Authenticate user credentials.
     * Returns the AuthenticatedSubject on success, or null on failure.
     *
     * @param array<string, mixed> $credentials
     */
    public function authenticate(array $credentials, Request $request): ?AuthenticatedSubject;

    /**
     * Terminate the active session / log out.
     */
    public function logout(Request $request): void;

    /**
     * Determine whether the given subject is authorized to access a specific workspace.
     */
    public function isAuthorizedForWorkspace(AuthenticatedSubject $subject, int $workspaceId): bool;

    /**
     * Return the workspace ids the subject may access, or null if unrestricted (e.g. an admin).
     *
     * @return array<int>|null
     */
    public function accessibleWorkspaceIds(AuthenticatedSubject $subject): ?array;

    /**
     * Return the tenant ids the subject has any membership in, or null if unrestricted (e.g. an admin).
     *
     * @return array<int>|null
     */
    public function accessibleTenantIds(AuthenticatedSubject $subject): ?array;
}
