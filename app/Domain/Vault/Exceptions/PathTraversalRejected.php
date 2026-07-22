<?php

namespace App\Domain\Vault\Exceptions;

use RuntimeException;

final class PathTraversalRejected extends RuntimeException
{
    public function __construct(
        public readonly int $workspaceId,
        public readonly string $attemptedPath,
        string $message = 'Path resolves outside the workspace vault root.',
    ) {
        parent::__construct($message);
    }
}
