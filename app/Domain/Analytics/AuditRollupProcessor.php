<?php

namespace App\Domain\Analytics;

use App\Models\AuditLog;
use App\Models\AuditRollup;
use App\Models\AuditRollupCursor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class AuditRollupProcessor
{
    private const STREAM = 'audit_log';

    public function process(int $batchSize): AuditRollupBatchResult
    {
        $batchSize = max(1, $batchSize);

        return DB::transaction(function () use ($batchSize): AuditRollupBatchResult {
            $now = now();

            DB::table('audit_rollup_cursors')->insertOrIgnore([
                'stream' => self::STREAM,
                'last_audit_id' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $cursor = AuditRollupCursor::query()
                ->where('stream', self::STREAM)
                ->lockForUpdate()
                ->firstOrFail();

            $audits = AuditLog::query()
                ->where('id', '>', $cursor->last_audit_id)
                ->orderBy('id')
                ->limit($batchSize)
                ->get();

            if ($audits->isEmpty()) {
                return new AuditRollupBatchResult(0, 0, $cursor->last_audit_id);
            }

            $rollups = [];
            $skipped = 0;

            foreach ($audits as $audit) {
                if ($audit->workspace_id === null) {
                    $skipped++;
                    continue;
                }

                $this->addContribution(
                    $rollups,
                    $audit,
                    RollupDimension::EVENT,
                    (string) $audit->event,
                );

                if ($audit->note_id !== null) {
                    $this->addContribution(
                        $rollups,
                        $audit,
                        RollupDimension::NOTE,
                        (string) $audit->note_id,
                    );
                }

                if ($audit->actor_subject_id !== null) {
                    $this->addContribution(
                        $rollups,
                        $audit,
                        RollupDimension::ACTOR,
                        (string) $audit->actor_subject_id,
                    );
                }
            }

            foreach ($rollups as $rollup) {
                $existing = AuditRollup::query()
                    ->where('workspace_id', $rollup['workspace_id'])
                    ->where('period_start', $rollup['period_start'])
                    ->where('dimension', $rollup['dimension'])
                    ->where('dimension_key', $rollup['dimension_key'])
                    ->lockForUpdate()
                    ->first();

                if ($existing === null) {
                    AuditRollup::query()->create($rollup);
                    continue;
                }

                $firstSeenAt = $existing->first_seen_at === null
                    || $rollup['first_seen_at']->lt($existing->first_seen_at)
                    ? $rollup['first_seen_at']
                    : $existing->first_seen_at;
                $lastSeenAt = $existing->last_seen_at === null
                    || $rollup['last_seen_at']->gt($existing->last_seen_at)
                    ? $rollup['last_seen_at']
                    : $existing->last_seen_at;

                $existing->forceFill([
                    'count' => $existing->count + $rollup['count'],
                    'first_seen_at' => $firstSeenAt,
                    'last_seen_at' => $lastSeenAt,
                ])->save();
            }

            $cursor->forceFill([
                'last_audit_id' => $audits->last()->id,
            ])->save();

            return new AuditRollupBatchResult(
                $audits->count(),
                $skipped,
                $audits->last()->id,
            );
        });
    }

    /**
     * @param array<string, array{workspace_id: int, period_start: string, dimension: string, dimension_key: string, count: int, first_seen_at: Carbon, last_seen_at: Carbon}> $rollups
     */
    private function addContribution(
        array &$rollups,
        AuditLog $audit,
        RollupDimension $dimension,
        string $dimensionKey,
    ): void {
        $observedAt = Carbon::parse($audit->created_at)->utc();
        $attributes = [
            'workspace_id' => (int) $audit->workspace_id,
            'period_start' => $observedAt->toDateString(),
            'dimension' => $dimension->value,
            'dimension_key' => $dimensionKey,
        ];
        $key = json_encode($attributes, JSON_THROW_ON_ERROR);

        if (! isset($rollups[$key])) {
            $rollups[$key] = $attributes + [
                'count' => 1,
                'first_seen_at' => $observedAt,
                'last_seen_at' => $observedAt,
            ];

            return;
        }

        $rollups[$key]['count']++;
        if ($observedAt->lt($rollups[$key]['first_seen_at'])) {
            $rollups[$key]['first_seen_at'] = $observedAt;
        }
        if ($observedAt->gt($rollups[$key]['last_seen_at'])) {
            $rollups[$key]['last_seen_at'] = $observedAt;
        }
    }
}
