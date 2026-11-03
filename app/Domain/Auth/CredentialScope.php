<?php

namespace App\Domain\Auth;

use App\Domain\Auth\Providers\LocalIdentityProvider;
use App\Models\Workspace;
use WeakMap;

/**
 * Workspace reach of a bearer credential, shared by the IdentityProvider
 * decorator and NoteAccess so both seams agree (decisions D2/D3 in
 * docs/decisions.md):
 *
 * - only workspaces of the token's own tenant;
 * - a token owned by an administrator reaches exactly its explicit
 *   `workspace_ids` list (no administrator bypass, null = nothing);
 * - any other token reaches the owner's explicit memberships, intersected with
 *   `workspace_ids` when the list is set;
 * - writing additionally needs a write scope and, for non-administrators, a
 *   writer role in that workspace.
 *
 * Membership is always evaluated with the local rules: credentials belong to
 * local users whatever the configured provider is.
 */
final class CredentialScope
{
    /** @var WeakMap<AuthenticatedSubject, list<int>>|null */
    private static ?WeakMap $cache = null;

    public function __construct(
        private readonly LocalIdentityProvider $local = new LocalIdentityProvider,
    ) {}

    /**
     * @return list<int>
     */
    public function workspaceIds(AuthenticatedSubject $subject): array
    {
        $credential = $subject->credential;
        if ($credential === null) {
            return [];
        }

        self::$cache ??= new WeakMap;
        if (isset(self::$cache[$subject])) {
            return self::$cache[$subject];
        }

        $tenantWorkspaceIds = Workspace::query()->where('tenant_id', $credential->tenantId)->pluck('id')
            ->map(static fn ($id): int => (int) $id)->all();

        if ($credential->ownerIsAdmin) {
            $allowed = $credential->workspaceIds ?? [];
        } else {
            $memberships = $this->local->accessibleWorkspaceIds($subject) ?? [];
            $allowed = $credential->workspaceIds === null
                ? $memberships
                : array_intersect($memberships, $credential->workspaceIds);
        }

        $ids = array_values(array_unique(array_map('intval', array_intersect($allowed, $tenantWorkspaceIds))));
        sort($ids);

        return self::$cache[$subject] = $ids;
    }

    public function allowsWorkspace(AuthenticatedSubject $subject, int $workspaceId): bool
    {
        return in_array($workspaceId, $this->workspaceIds($subject), true);
    }

    public function canWrite(AuthenticatedSubject $subject, int $workspaceId): bool
    {
        $credential = $subject->credential;
        if ($credential === null || ! $this->allowsWorkspace($subject, $workspaceId)) {
            return false;
        }

        if (! $credential->hasScope(CredentialContext::SCOPE_API_WRITE)
            && ! $credential->hasScope(CredentialContext::SCOPE_MCP_WRITE)) {
            return false;
        }

        return $credential->ownerIsAdmin || $this->local->canWriteWorkspace($subject, $workspaceId);
    }
}
