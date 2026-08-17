<?php

namespace App\Domain\Vault\Exceptions;

use RuntimeException;

final class TrashRestoreConflict extends RuntimeException
{
    public function __construct(public readonly string $originalPath)
    {
        parent::__construct("Cannot restore note because [{$originalPath}] is already occupied.");
    }
}
