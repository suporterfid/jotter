<?php

namespace App\Domain\Auth;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Validated issuance options for a manual machine token (decisions D1–D3, D7).
 * Construct with fromInput(); every rule failure throws
 * InvalidMachineTokenOptions with one message per field.
 */
final class MachineTokenOptions
{
    public const OPS = ['create', 'append', 'update'];

    /** Folders no credential may ever write to, whatever its write paths say (D7). */
    public const FORBIDDEN_WRITE_PREFIXES = ['_brain/', '.trash/', '_resources/', '_templates/', '.obsidian/'];

    private const CLIENT_SLUG_PATTERN = '/^[a-z0-9][a-z0-9-]{0,39}$/';

    /**
     * @param list<string> $scopes
     * @param list<int>|null $workspaceIds
     * @param list<array{glob: string, ops: list<string>}> $writePaths
     */
    private function __construct(
        public readonly array $scopes,
        public readonly ?array $workspaceIds,
        public readonly array $writePaths,
        public readonly ?string $clientSlug,
        public readonly ?CarbonImmutable $expiresAt,
    ) {}

    /** Default options: read-only MCP, owner's memberships, no expiry. */
    public static function readOnly(): self
    {
        return new self([CredentialContext::SCOPE_MCP_READ], null, [], null, null);
    }

    /**
     * @param list<string> $scopes
     * @param list<int|string>|null $workspaces ids or slugs of workspaces in $tenant
     * @param list<array{glob: string, ops: list<string>}|string> $writePaths `{glob, ops}` or "glob:op,op"
     */
    public static function fromInput(
        User $owner,
        Tenant $tenant,
        array $scopes = [CredentialContext::SCOPE_MCP_READ],
        ?array $workspaces = null,
        array $writePaths = [],
        ?string $clientSlug = null,
        ?string $expires = null,
    ): self {
        $errors = [];

        $scopes = array_values(array_unique(array_map(static fn ($scope): string => trim((string) $scope), $scopes)));
        if ($scopes === []) {
            $errors['scopes'] = 'At least one scope is required.';
        } elseif ($unknown = array_diff($scopes, CredentialContext::SCOPES)) {
            $errors['scopes'] = 'Unknown scope(s): '.implode(', ', $unknown).'. Allowed: '.implode(', ', CredentialContext::SCOPES).'.';
        }

        $workspaceIds = null;
        if ($workspaces !== null && $workspaces !== []) {
            $workspaceIds = [];
            foreach ($workspaces as $reference) {
                $workspace = Workspace::query()->where('tenant_id', $tenant->id)
                    ->where(static function ($query) use ($reference): void {
                        $query->where('slug', (string) $reference);
                        if (ctype_digit((string) $reference)) {
                            $query->orWhere('id', (int) $reference);
                        }
                    })->first();
                if ($workspace === null) {
                    $errors['workspaces'] = sprintf('Workspace [%s] is not in tenant [%s].', $reference, $tenant->slug);
                    break;
                }
                $workspaceIds[] = (int) $workspace->id;
            }
            $workspaceIds = array_values(array_unique($workspaceIds));
        }

        if ($owner->is_admin && $workspaceIds === null && ! isset($errors['workspaces'])) {
            $errors['workspaces'] = 'Tokens owned by an administrator must list their workspaces explicitly.';
        }

        $normalizedPaths = [];
        foreach ($writePaths as $index => $entry) {
            try {
                $normalizedPaths[] = self::normalizeWritePath($entry);
            } catch (InvalidArgumentException $exception) {
                $errors['write_paths'] = sprintf('Write path #%d: %s', $index + 1, $exception->getMessage());
                break;
            }
        }

        if ($normalizedPaths !== [] && ! in_array(CredentialContext::SCOPE_MCP_WRITE, $scopes, true)) {
            $errors['write_paths'] = 'Write paths require the mcp:write scope.';
        }
        if (in_array(CredentialContext::SCOPE_MCP_WRITE, $scopes, true) && $normalizedPaths === [] && ! isset($errors['write_paths'])) {
            $errors['write_paths'] = 'The mcp:write scope needs at least one write path.';
        }

        $clientSlug = $clientSlug !== null && trim($clientSlug) !== '' ? strtolower(trim($clientSlug)) : null;
        if ($clientSlug !== null && preg_match(self::CLIENT_SLUG_PATTERN, $clientSlug) !== 1) {
            $errors['client'] = 'Client slug must be 1–40 lowercase letters, digits, or dashes.';
        }
        $usesClient = array_filter($normalizedPaths, static fn (array $path): bool => str_contains($path['glob'], '{client}')) !== [];
        if ($usesClient && $clientSlug === null && ! isset($errors['client'])) {
            $errors['client'] = 'A write path uses {client}; set the client slug.';
        }

        $expiresAt = null;
        try {
            $expiresAt = self::parseExpiry($expires);
        } catch (InvalidArgumentException $exception) {
            $errors['expires'] = $exception->getMessage();
        }

        if ($errors !== []) {
            throw new InvalidMachineTokenOptions($errors);
        }

        return new self($scopes, $workspaceIds, $normalizedPaths, $clientSlug, $expiresAt);
    }

    /**
     * @param array{glob?: mixed, ops?: mixed}|string $entry
     * @return array{glob: string, ops: list<string>}
     */
    public static function normalizeWritePath(array|string $entry): array
    {
        if (is_string($entry)) {
            $separator = strrpos($entry, ':');
            if ($separator === false) {
                throw new InvalidArgumentException('use the form "glob:op,op" (for example "inbox/{client}/**:create,append").');
            }
            $entry = ['glob' => substr($entry, 0, $separator), 'ops' => explode(',', substr($entry, $separator + 1))];
        }

        $glob = trim((string) ($entry['glob'] ?? ''));
        $ops = array_values(array_unique(array_filter(array_map(static fn ($op): string => strtolower(trim((string) $op)), (array) ($entry['ops'] ?? [])))));

        if ($glob === '') {
            throw new InvalidArgumentException('the glob is empty.');
        }
        if (str_starts_with($glob, '/') || str_contains($glob, '\\') || in_array('..', explode('/', $glob), true)) {
            throw new InvalidArgumentException('the glob must be relative to the vault root, without ".." or backslashes.');
        }
        // The top-level folder must be literal ({client} is allowed: client slugs
        // can never start with "_" or "."), so no glob can reach a protected folder.
        $topLevel = explode('/', $glob)[0];
        if (strpbrk(str_replace('{client}', '', $topLevel), '*?[]{}') !== false) {
            throw new InvalidArgumentException(sprintf('"%s": the top-level folder must be literal (for example "inbox/{client}/**").', $glob));
        }
        foreach (self::FORBIDDEN_WRITE_PREFIXES as $prefix) {
            if (str_starts_with($glob.'/', $prefix)) {
                throw new InvalidArgumentException(sprintf('"%s" points into %s, which credentials may never write.', $glob, $prefix));
            }
        }
        if ($ops === []) {
            throw new InvalidArgumentException('at least one operation is required ('.implode(', ', self::OPS).').');
        }
        if ($unknown = array_diff($ops, self::OPS)) {
            throw new InvalidArgumentException('unknown operation(s) '.implode(', ', $unknown).'; allowed: '.implode(', ', self::OPS).'.');
        }

        return ['glob' => $glob, 'ops' => $ops];
    }

    private static function parseExpiry(?string $expires): ?CarbonImmutable
    {
        $expires = $expires === null ? '' : strtolower(trim($expires));
        if ($expires === '' || $expires === 'never') {
            return null;
        }

        if (preg_match('/^(\d{1,4})([dy])$/', $expires, $matches) !== 1 || (int) $matches[1] < 1) {
            throw new InvalidArgumentException('Expiry must be "never" or a duration like 30d, 180d, or 1y.');
        }

        $amount = (int) $matches[1];

        return $matches[2] === 'y' ? CarbonImmutable::now()->addYears($amount) : CarbonImmutable::now()->addDays($amount);
    }
}
