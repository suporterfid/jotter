<?php

namespace App\Domain\Vault\Store;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * The configured store cannot be used (driver disabled, not implemented yet,
 * misconfigured or unreachable). Rendered as 503 "external storage
 * unavailable" (plan §6.5); the S3 driver also marks the workspace `degraded`.
 */
final class StoreUnavailable extends RuntimeException
{
    public function render(): JsonResponse
    {
        return response()->json([
            'message' => __('messages.vault_store_unavailable'),
            'storage_state' => 'degraded',
        ], 503, ['Retry-After' => '60']);
    }
}
