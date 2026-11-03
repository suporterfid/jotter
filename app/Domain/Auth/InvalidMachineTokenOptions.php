<?php

namespace App\Domain\Auth;

use InvalidArgumentException;

/**
 * Machine token issuance options failed validation; errors() maps a field
 * (scopes, workspaces, write_paths, client, expires) to its message.
 */
final class InvalidMachineTokenOptions extends InvalidArgumentException
{
    /**
     * @param array<string, string> $errors
     */
    public function __construct(private readonly array $errors)
    {
        parent::__construct(implode(' ', $errors));
    }

    /**
     * @return array<string, string>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
