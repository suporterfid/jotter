<?php

namespace App\Domain\Vault\Store\GitHub;

/** Result of `GET /git/ref/heads/{branch}`. */
final class GitHubRef
{
    public const OK = 'ok';

    public const NOT_MODIFIED = 'not_modified';

    /** The branch does not exist (deleted, renamed, never created). */
    public const MISSING = 'missing';

    /** The repository has no commit at all (409 from the Git database API). */
    public const EMPTY = 'empty';

    private function __construct(
        public readonly string $status,
        public readonly ?string $sha = null,
        public readonly ?string $etag = null,
    ) {}

    public static function at(string $sha, ?string $etag): self
    {
        return new self(self::OK, $sha, $etag);
    }

    public static function notModified(?string $etag): self
    {
        return new self(self::NOT_MODIFIED, null, $etag);
    }

    public static function missing(): self
    {
        return new self(self::MISSING);
    }

    public static function empty(): self
    {
        return new self(self::EMPTY);
    }
}
