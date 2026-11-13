<?php

namespace App\Domain\Vault\Store;

final class StoreCapabilities
{
    public function __construct(
        /** Native compare-and-swap on write/delete (If-Match, ref update, local lock). */
        public readonly bool $conditionalWrite = true,
        /** Empty directories survive (S3 needs a marker, Git a .gitkeep). */
        public readonly bool $emptyDirs = true,
        /** Largest file the store accepts; null = no driver limit. */
        public readonly ?int $maxFileBytes = null,
        /** Writes are journaled and pushed later (GitHub). */
        public readonly bool $writeBehind = false,
        /** Can hand out short-lived download URLs. */
        public readonly bool $presignedUrls = false,
    ) {}
}
