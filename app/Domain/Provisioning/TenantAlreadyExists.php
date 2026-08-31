<?php

namespace App\Domain\Provisioning;

final class TenantAlreadyExists extends \RuntimeException
{
    public function __construct(public readonly string $slug)
    {
        parent::__construct("Tenant [{$slug}] already exists. Provisioning is not repeated; use tenant:plan / tenant:show to manage it.");
    }
}
