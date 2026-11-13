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

    /** True when $contents can travel inline as `content` in POST /git/trees (UTF-8 text). */
    public static function isInlineText(string $contents): bool
    {
        return ! str_contains($contents, "\0") && mb_check_encoding($contents, 'UTF-8');
    }
}
