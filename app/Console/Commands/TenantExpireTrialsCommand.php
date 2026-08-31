<?php

namespace App\Console\Commands;

use App\Domain\Plan\TenantPlan;
use Illuminate\Console\Command;

/**
 * Scheduled daily. Moves trials past their deadline to read_only and writes one
 * audit row per tenant. Idempotent: already read-only tenants are not touched.
 */
final class TenantExpireTrialsCommand extends Command
{
    protected $signature = 'tenant:expire-trials';

    protected $description = 'Move expired hosted-mode trials to read_only (scheduler task).';

    public function handle(TenantPlan $tenantPlan): int
    {
        $expired = $tenantPlan->expireTrials();

        $this->info(sprintf('Expired %d trial(s)%s.', count($expired), $expired === [] ? '' : ': '.implode(', ', $expired)));

        return self::SUCCESS;
    }
}
