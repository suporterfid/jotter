<?php

namespace App\Domain\Auth;

use App\Models\MachineToken;
use Carbon\CarbonImmutable;

/**
 * What a bearer credential (manual machine token or, later, an OAuth access
 * token) is allowed to do, independent of the user it acts for. Attached to
 * the AuthenticatedSubject so every authorization seam can narrow the user's
 * own rights to the credential's scopes and workspace allowlist.
 */
final class CredentialContext
{
    public const SCOPE_MCP_READ = 'mcp:read';
    public const SCOPE_MCP_WRITE = 'mcp:write';
    public const SCOPE_API_READ = 'api:read';
    public const SCOPE_API_WRITE = 'api:write';

    public const SCOPES = [
        self::SCOPE_MCP_READ,
        self::SCOPE_MCP_WRITE,
        self::SCOPE_API_READ,
        self::SCOPE_API_WRITE,
    ];

    /**
     * @param list<string> $scopes
     * @param list<int>|null $workspaceIds null = the owner's explicit memberships
     * @param list<array{glob: string, ops: list<string>}> $writePaths
     */
    public function __construct(
        public readonly int $tokenId,
        public readonly int $tenantId,
        public readonly string $kind,
        public readonly array $scopes,
        public readonly ?array $workspaceIds,
        public readonly array $writePaths,
        public readonly ?int $clientId,
        public readonly ?string $clientSlug,
        public readonly ?CarbonImmutable $expiresAt,
        public readonly bool $ownerIsAdmin,
    ) {}

    public static function fromToken(MachineToken $token, bool $ownerIsAdmin): self
    {
        return new self(
            tokenId: (int) $token->id,
            tenantId: (int) $token->tenant_id,
            kind: (string) ($token->kind ?? MachineToken::KIND_MANUAL),
            scopes: array_values(array_map('strval', $token->scopes ?? [])),
            workspaceIds: $token->workspace_ids === null ? null : array_values(array_map('intval', $token->workspace_ids)),
            writePaths: array_values($token->write_paths ?? []),
            clientId: $token->client_id !== null ? (int) $token->client_id : null,
            clientSlug: $token->client_slug,
            expiresAt: $token->expires_at !== null ? CarbonImmutable::instance($token->expires_at) : null,
            ownerIsAdmin: $ownerIsAdmin,
        );
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    /**
     * Audit metadata identifying the credential, merged into every event a
     * credential triggers.
     *
     * @return array{token_id: int, client_slug: string|null}
     */
    public function auditMetadata(): array
    {
        return ['token_id' => $this->tokenId, 'client_slug' => $this->clientSlug];
    }
}
