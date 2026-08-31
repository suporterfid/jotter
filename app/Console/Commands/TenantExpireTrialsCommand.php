<?php

namespace App\Console\Commands;

use App\Domain\Plan\TenantPlan;
use App\Domain\Plan\TrialNotifier;
use App\Models\Tenant;
use Illuminate\Console\Command;

/**
 * Scheduled daily. Reminds owners REMINDER_DAYS before a trial ends, moves trials
 * past their deadline to read_only (one audit row per tenant), and e-mails the
 * owners once. Idempotent: markers on the tenant prevent repeats.
 */
final class TenantExpireTrialsCommand extends Command
{
    protected $signature = 'tenant:expire-trials';

    protected $description = 'Remind about ending trials and move expired hosted-mode trials to read_only (scheduler task).';

    public function handle(TenantPlan $tenantPlan, TrialNotifier $notifier): int
    {
        $reminded = $notifier->sendReminders();
        $expired = $tenantPlan->expireTrials();
        $notified = $notifier->sendEnded($expired);
        $slugs = array_map(static fn (Tenant $tenant): string => $tenant->slug, $expired);

        $this->info(sprintf('Reminded %d trial(s)%s.', count($reminded), $reminded === [] ? '' : ': '.implode(', ', $reminded)));
        $this->info(sprintf('Expired %d trial(s)%s.', count($slugs), $slugs === [] ? '' : ': '.implode(', ', $slugs)));
        $this->info(sprintf('Notified %d trial(s) ended.', count($notified)));

        return self::SUCCESS;
    }
}
