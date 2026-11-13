<?php

namespace App\Domain\Vault\Store;

use RuntimeException;

final class StoreVersionConflict extends RuntimeException
{
    public function __construct(
        public readonly string $path,
        public readonly ?string $expectedVersion,
        public readonly ?string $currentVersion,
    ) {
        parent::__construct("Vault file [{$path}] changed: expected version [".($expectedVersion ?? '-').'], found ['.($currentVersion ?? 'missing').'].');
    }
}
