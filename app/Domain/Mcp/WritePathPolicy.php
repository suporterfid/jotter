<?php

namespace App\Domain\Mcp;

use App\Domain\Auth\CredentialContext;
use App\Domain\Auth\MachineTokenOptions;

/**
 * Decides whether a credential may perform a write operation on a vault path,
 * using the token's `write_paths` (`{glob, ops}`) and the folders no credential
 * may ever write (decision D7).
 *
 * Globs are matched with our own translation, not fnmatch(): `**` crosses
 * folders, `*` and `?` stay inside one path segment, and `{client}` is the
 * token's client slug. The whole relative path must match.
 */
final class WritePathPolicy
{
    public function allows(CredentialContext $credential, string $relativePath, string $op): bool
    {
        if (self::isProtected($relativePath) || ! str_ends_with(strtolower($relativePath), '.md')) {
            return false;
        }

        foreach ($credential->writePaths as $entry) {
            $ops = (array) ($entry['ops'] ?? []);
            if (! in_array($op, $ops, true)) {
                continue;
            }

            $regex = self::globToRegex((string) ($entry['glob'] ?? ''), $credential->clientSlug);
            if ($regex !== null && preg_match($regex, $relativePath) === 1) {
                return true;
            }
        }

        return false;
    }

    public static function isProtected(string $relativePath): bool
    {
        $path = ltrim($relativePath, '/');
        foreach (MachineTokenOptions::FORBIDDEN_WRITE_PREFIXES as $prefix) {
            if (str_starts_with($path.'/', $prefix) || str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns null when the glob cannot match safely (empty, or `{client}`
     * without a client slug on the token).
     */
    public static function globToRegex(string $glob, ?string $clientSlug): ?string
    {
        if ($glob === '') {
            return null;
        }

        if (str_contains($glob, '{client}')) {
            if ($clientSlug === null || $clientSlug === '') {
                return null;
            }
        }

        $regex = '';
        $length = strlen($glob);
        for ($i = 0; $i < $length; $i++) {
            if (substr($glob, $i, 8) === '{client}') {
                $regex .= preg_quote((string) $clientSlug, '#');
                $i += 7;

                continue;
            }

            $char = $glob[$i];
            if ($char === '*' && ($glob[$i + 1] ?? '') === '*') {
                // "**/" also matches zero folders, so "inbox/**/x.md" matches "inbox/x.md".
                if (($glob[$i + 2] ?? '') === '/') {
                    $regex .= '(?:.*/)?';
                    $i += 2;
                } else {
                    $regex .= '.*';
                    $i += 1;
                }

                continue;
            }

            $regex .= match ($char) {
                '*' => '[^/]*',
                '?' => '[^/]',
                default => preg_quote($char, '#'),
            };
        }

        return '#\A'.$regex.'\z#u';
    }
}
