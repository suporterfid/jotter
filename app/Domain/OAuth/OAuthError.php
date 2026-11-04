<?php

namespace App\Domain\OAuth;

use RuntimeException;

/**
 * An RFC 6749 / RFC 7591 error response: `{error, error_description}`.
 */
final class OAuthError extends RuntimeException
{
    public function __construct(
        public readonly string $error,
        public readonly string $description,
        public readonly int $status = 400,
    ) {
        parent::__construct($error.': '.$description);
    }

    /**
     * @return array{error: string, error_description: string}
     */
    public function toArray(): array
    {
        return ['error' => $this->error, 'error_description' => $this->description];
    }
}
