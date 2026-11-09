<?php

namespace App\Domain\Quota;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A write would take a workspace past its storage quota. Rendered as
 * 507 Insufficient Storage (RFC 4918), which WebDAV clients understand too.
 */
final class QuotaExceeded extends RuntimeException
{
    public function __construct(
        public readonly int $usedBytes,
        public readonly int $limitBytes,
        public readonly int $requestedBytes,
    ) {
        parent::__construct(__('messages.storage_quota_exceeded', [
            'used' => WorkspaceQuota::human($usedBytes),
            'limit' => WorkspaceQuota::human($limitBytes),
        ]));
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'used_bytes' => $this->usedBytes,
            'limit_bytes' => $this->limitBytes,
            'requested_bytes' => $this->requestedBytes,
        ], 507);
    }
}
