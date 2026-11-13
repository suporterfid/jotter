<?php

namespace App\Domain\Vault\Store;

use App\Domain\Vault\Exceptions\PathTraversalRejected;
use Normalizer;

/**
 * Logical path rules shared by every VaultStore driver: a workspace-relative,
 * `/`-separated key that cannot escape the vault or touch Git metadata.
 *
 * Rejects `..`/`.`/empty segments, absolute paths, drive letters and `:`,
 * NUL, `\`, any `.git` segment, and keys longer than 1024 bytes. Remote
 * drivers also normalize Unicode to NFC (Obsidian on macOS writes NFD); the
 * local driver keeps bytes as they are, so existing files stay addressable.
 */
final class VaultPathNormalizer
{
    public const MAX_KEY_BYTES = 1024;

    /**
     * @param  bool  $allowRoot  accept '' (the vault root itself), for directory operations
     *
     * @throws PathTraversalRejected
     */
    public static function normalize(string $path, bool $nfc = true, int $workspaceId = 0, bool $allowRoot = false): string
    {
        $candidate = $path;

        if ($allowRoot && trim($candidate, '/') === '') {
            return '';
        }

        $reject = static function (string $reason) use ($path, $workspaceId): never {
            throw new PathTraversalRejected($workspaceId, $path, "Unsafe vault path: {$reason}.");
        };

        if ($candidate === '' || str_contains($candidate, "\0")) {
            $reject('empty or NUL');
        }
        if (str_contains($candidate, '\\')) {
            $reject('backslash');
        }
        if (str_starts_with($candidate, '/') || preg_match('/^[a-zA-Z]:/', $candidate) === 1) {
            $reject('absolute');
        }

        if ($nfc && class_exists(Normalizer::class)) {
            $normalized = Normalizer::normalize($candidate, Normalizer::FORM_C);
            if ($normalized === false) {
                $reject('invalid UTF-8');
            }
            $candidate = $normalized;
        }

        if ($allowRoot) {
            $candidate = rtrim($candidate, '/');
        }

        foreach (explode('/', $candidate) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                $reject('relative segment');
            }
            if (str_contains($segment, ':')) {
                $reject('colon');
            }
            if (strtolower($segment) === '.git') {
                $reject('.git');
            }
        }

        if (strlen($candidate) > self::MAX_KEY_BYTES) {
            $reject('longer than '.self::MAX_KEY_BYTES.' bytes');
        }

        return $candidate;
    }
}
