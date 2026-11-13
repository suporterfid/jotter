<?php

namespace App\Domain\Vault\Store\GitHub;

/** Git object hashing done locally, so versions never need a GitHub call. */
final class GitHubGit
{
    /** SHA-1 of a Git blob: what GitHub stores as the file's `sha`. */
    public static function blobSha(string $contents): string
    {
        return sha1('blob '.strlen($contents)."\0".$contents);
    }

    /**
     * Largest text sent inline in POST /git/trees. GitHub documents no limit
     * (spike V3); larger texts go through POST /git/blobs like binaries, which
     * costs one more write but never hits an undocumented ceiling.
     */
    public const INLINE_MAX_BYTES = 1024 * 1024;

    /** True when $contents can travel inline as `content` in POST /git/trees (UTF-8 text, ≤ 1 MiB). */
    public static function isInlineText(string $contents): bool
    {
        return strlen($contents) <= self::INLINE_MAX_BYTES && ! str_contains($contents, "\0") && mb_check_encoding($contents, 'UTF-8');
    }
}
