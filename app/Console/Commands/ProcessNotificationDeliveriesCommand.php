<?php

namespace App\Console\Commands;

use App\Domain\Notifications\NotificationEmailService;
use App\Models\NotificationDelivery;
use Illuminate\Console\Command;

/**
 * Sends notification deliveries that were handed to the JobDispatcher. On shared
 * hosting the local dispatcher only records the job, so this bounded command is
 * the scheduler-driven executor for `SendNotificationEmail`.
 */
final class ProcessNotificationDeliveriesCommand extends Command
{
    protected $signature = 'notifications:process-deliveries {--limit=50 : Maximum deliveries to send in this run}';

    protected $description = 'Send pending notification email deliveries (bounded, cron-safe).';

    public function handle(NotificationEmailService $service): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $deliveries = NotificationDelivery::query()
            ->where('channel', 'email')
            ->where('status', 'pending')
            ->orderBy('dispatched_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $sent = 0;
        $failed = 0;
        foreach ($deliveries as $delivery) {
            $service->sendDelivery($delivery);
            $delivery->refresh()->status === 'sent' ? $sent++ : $failed++;
        }

        $this->info("Processed {$deliveries->count()} delivery(ies): {$sent} sent, {$failed} failed.");

        return self::SUCCESS;
    }
}
