<?php

namespace App\Console\Commands;

use App\Domain\Analytics\AuditRollupProcessor;
use Illuminate\Console\Command;

final class AuditRollupCommand extends Command
{
    protected $signature = 'analytics:rollup {--batch= : Maximum source rows per invocation}';

    protected $description = 'Incrementally roll up audit activity for workspace analytics.';

    public function handle(AuditRollupProcessor $processor): int
    {
        $configuredBatch = (int) config('jotter.analytics.rollup_batch_size', 500);
        if ($configuredBatch <= 0) {
            $configuredBatch = 500;
        }

        $batch = $this->option('batch');
        $batch = $batch === null ? $configuredBatch : (int) $batch;
        if ($batch <= 0) {
            $batch = $configuredBatch;
        }

        $result = $processor->process($batch);

        $this->info(
            "Processed {$result->processed} audit rows; "
            ."skipped {$result->skipped} audit rows; "
            ."cursor now at {$result->lastAuditId}.",
        );

        return self::SUCCESS;
    }
}
