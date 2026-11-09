<?php

namespace App\Console\Commands;

use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditRecorder;
use App\Models\AuditLog;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Audit-log retention. Two windows (LGPD minimisation vs. Marco Civil art. 15):
 * access records (AuditEvent::accessRecordValues(): sign-in, sign-out, OAuth
 * tokens, MCP connections, with IP) are kept for the access window, every other
 * event for the audit window. The access window is never shorter than the audit
 * window, so turning it on can only keep more access records, never fewer.
 */
final class AuditPruneCommand extends Command
{
    protected $signature = 'audit:prune
        {--days= : Days to keep audit events (default JOTTER_AUDIT_RETENTION_DAYS, 90)}
        {--access-days= : Days to keep access records (default JOTTER_ACCESS_LOG_RETENTION_DAYS, else --days)}';

    protected $description = 'Prune audit logs older than the retention windows (access records may be kept longer).';

    public function handle(AuditRecorder $recorder): int
    {
        $days = self::positive($this->option('days')) ?? self::positive(config('jotter.audit.retention_days')) ?? 90;
        $accessDays = max($days, self::positive($this->option('access-days')) ?? self::positive(config('jotter.audit.access_retention_days')) ?? $days);

        $now = Carbon::now();
        $cutoff = $now->copy()->subDays($days);
        $accessCutoff = $now->copy()->subDays($accessDays);
        $accessEvents = AuditEvent::accessRecordValues();

        $pruned = $this->pruneWhere(static fn (Builder $query) => $query
            ->whereNotIn('event', $accessEvents)
            ->where('created_at', '<', $cutoff));
        $accessPruned = $this->pruneWhere(static fn (Builder $query) => $query
            ->whereIn('event', $accessEvents)
            ->where('created_at', '<', $accessCutoff));
        $totalPruned = $pruned + $accessPruned;

        $recorder->record(
            AuditEvent::SYSTEM_AUDIT_PRUNED,
            null,
            null,
            'system',
            [
                'retention_days' => $days,
                'access_retention_days' => $accessDays,
                'rows_pruned' => $totalPruned,
                'access_rows_pruned' => $accessPruned,
                'cutoff' => $cutoff->toIso8601String(),
                'access_cutoff' => $accessCutoff->toIso8601String(),
            ]
        );

        $this->info("Audit log prune completed: {$totalPruned} entries removed (retention: {$days} days; access records: {$accessDays} days).");

        return self::SUCCESS;
    }

    /**
     * @param  callable(Builder<AuditLog>): Builder<AuditLog>  $scope
     */
    private function pruneWhere(callable $scope): int
    {
        $total = 0;

        AuditLog::withoutEvents(function () use ($scope, &$total): void {
            do {
                $deleted = $scope(AuditLog::query())->limit(500)->delete();
                $total += $deleted;
            } while ($deleted > 0);
        });

        return $total;
    }

    private static function positive(mixed $value): ?int
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return (int) $value > 0 ? (int) $value : null;
    }
}
