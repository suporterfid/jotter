<?php

namespace App\Domain\Auth;

use App\Models\User;

final class AuthenticatedSubject
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        public readonly string $subjectId,
        public readonly string $email,
        public readonly string $name,
        public readonly bool $isAdmin = false,
        public readonly ?User $user = null,
        public readonly array $attributes = [],
        public readonly string $locale = 'pt-BR',
        public readonly ?CredentialContext $credential = null,
    ) {}

    /**
     * True when the request authenticated with a bearer credential (machine or
     * OAuth token) rather than a browser session.
     */
    public function isCredential(): bool
    {
        return $this->credential !== null;
    }
}
