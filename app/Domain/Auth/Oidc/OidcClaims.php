<?php

namespace App\Domain\Auth\Oidc;

final readonly class OidcClaims
{
    public function __construct(
        public string $issuer,
        public string $subject,
        public string $email,
        public bool $emailVerified,
        public string $name,
        public string $locale,
    ) {
    }
}
