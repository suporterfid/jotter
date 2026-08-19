<?php

namespace App\Domain\Analytics;

final readonly class AuditRollupBatchResult
{
    public function __construct(
        public int $processed,
        public int $skipped,
        public int $lastAuditId,
    ) {
    }
}
