<?php

namespace App\Domain\Vault\Store;

/**
 * One file in a VaultStore. `version` is the driver's change token: SHA-256 of
 * the bytes (local), the ETag (S3) or the blob SHA (Git).
 */
final class StoreEntry
{
    public function __construct(
        public readonly string $path,
        public readonly int $size,
        public readonly ?string $version = null,
        public readonly ?int $modifiedAt = null,
    ) {}
}
