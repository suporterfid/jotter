<?php

namespace App\Domain\Vault\Store\GitHub;

use RuntimeException;

/**
 * A GitHub REST call that did not succeed. The message never contains the
 * token; `status` is 0 for transport errors (timeout, DNS, TLS).
 */
class GitHubApiError extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message);
    }

    /** Primary or secondary rate limit: retry later, never fail the user's write. */
    public function isRateLimited(): bool
    {
        return $this->retryAfter !== null;
    }

    /** Credentials revoked, App uninstalled, repository gone or renamed, branch deleted. */
    public function isPermanent(): bool
    {
        return in_array($this->status, [401, 403, 404], true) && ! $this->isRateLimited();
    }

    /** `PATCH /git/refs` refused because someone pushed in between. */
    public function isNotFastForward(): bool
    {
        return $this->status === 422 && str_contains(strtolower($this->getMessage()), 'fast forward');
    }

    /** The repository has no commit yet: the Git database API answers 409. */
    public function isEmptyRepository(): bool
    {
        return $this->status === 409;
    }
}
