<?php

namespace App\Domain\Vault\Exceptions;

use RuntimeException;

final class VaultNoteNotFound extends RuntimeException
{
    public function __construct(string $relativePath)
    {
        parent::__construct("Vault note [{$relativePath}] does not exist.");
    }
}
