<?php

namespace App\Console\Commands;

use App\Domain\Privacy\ErasureLog;
use Illuminate\Console\Command;

/**
 * Drops erasure-log entries older than JOTTER_ERASURE_LOG_DAYS (block 4c).
 * Keep that longer than any backup copy lives, on or off the server.
 */
final class ErasureLogPruneCommand extends Command
{
    protected $signature = 'erasure-log:prune {--days= : Days to keep (default JOTTER_ERASURE_LOG_DAYS, 400)}';

    protected $description = 'Prune erasure-log entries older than the retention window.';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: config('jotter.erasure_log.days', 400));
        $pruned = (new ErasureLog)->prune($days);
        $this->info(sprintf('Erasure log: %d entr%s older than %d days removed.', $pruned, $pruned === 1 ? 'y' : 'ies', $days));

        return self::SUCCESS;
    }
}
