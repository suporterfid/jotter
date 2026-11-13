<?php

namespace App\Domain\Vault\Store;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** A file over the store's limit (GitHub: 10 MB by default, never above 100 MiB, D5). Rendered as 413. */
final class StoreFileTooLarge extends RuntimeException
{
    public function __construct(public readonly string $path, public readonly int $size, public readonly int $limit)
    {
        parent::__construct(__('messages.vault_file_too_large', ['limit' => (int) ceil($limit / 1024 / 1024)]));
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'limit_bytes' => $this->limit], 413);
    }
}
