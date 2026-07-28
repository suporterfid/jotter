<?php

namespace App\Console\Commands;

use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditRecorder;
use App\Models\AuditLog;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

final class AuditPruneCommand extends Command
{
    protected $signature = 'audit:prune {--days=90 : Number of days of audit logs to retain}';

    protected $description = 'Prune audit logs older than the specified retention window.';

    public function handle(AuditRecorder $recorder): int
    {
        $days = (int) $this->option('days');
        if ($days <= 0) {
            $days = (int) config('jotter.audit_retention_days', 90);
        }

        $cutoff = Carbon::now()->subDays($days);
        $totalPruned = 0;

        AuditLog::withoutEvents(function () use ($cutoff, &$totalPruned): void {
            do {
                $deleted = AuditLog::query()
                    ->where('created_at', '<', $cutoff)
                    ->limit(500)
                    ->delete();

                $totalPruned += $deleted;
            } while ($deleted > 0);
        });

        $recorder->record(
            AuditEvent::SYSTEM_AUDIT_PRUNED,
            null,
            null,
            'system',
            [
                'retention_days' => $days,
                'rows_pruned' => $totalPruned,
                'cutoff' => $cutoff->toIso8601String(),
            ]
        );

        $this->info("Audit log prune completed: {$totalPruned} entries removed (retention: {$days} days).");

        return self::SUCCESS;
    }
}
