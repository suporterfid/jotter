<?php

namespace App\Domain\Vault\Store;

final class StorePage
{
    /**
     * @param  list<StoreEntry>  $entries
     * @param  string|null  $nextCursor  null when the listing is complete
     */
    public function __construct(
        public readonly array $entries,
        public readonly ?string $nextCursor = null,
    ) {}
}
