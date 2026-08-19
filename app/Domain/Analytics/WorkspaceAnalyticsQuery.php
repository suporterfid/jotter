<?php

namespace App\Domain\Analytics;

use App\Domain\Auth\AuthenticatedSubject;
use App\Domain\Auth\NoteAccess;
use App\Models\AuditRollup;
use App\Models\Note;
use App\Models\Workspace;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class WorkspaceAnalyticsQuery
{
    public function __construct(
        private readonly NoteAccess $noteAccess,
    ) {}

    /**
     * @return array{
     *     workspace_id: int,
     *     period: array{days: int, from: string, to: string},
     *     most_active_notes: list<array<string, mixed>>,
     *     activity_over_time: list<array{period_start: string, count: int}>,
     *     activity_by_user: list<array{actor_subject_id: string, count: int}>,
     *     stale_notes: list<array<string, mixed>>,
     * }
     */
    public function forWorkspace(Workspace $workspace, AuthenticatedSubject $subject, int $days, int $limit): array
    {
        $days = max(1, min(90, $days));
        $limit = max(1, min(100, $limit));
        $to = CarbonImmutable::now('UTC')->startOfDay();
        $from = $to->subDays($days - 1);

        return [
            'workspace_id' => $workspace->id,
            'period' => [
                'days' => $days,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'most_active_notes' => $this->mostActiveNotes($workspace, $subject, $from, $to, $limit),
            'activity_over_time' => $this->activityOverTime($workspace, $from, $to),
            'activity_by_user' => $this->activityByUser($workspace, $from, $to, $limit),
            'stale_notes' => $this->staleNotes($workspace, $subject, $limit),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function mostActiveNotes(Workspace $workspace, AuthenticatedSubject $subject, CarbonImmutable $from, CarbonImmutable $to, int $limit): array
    {
        $visibleNotes = $this->visibleNotes($workspace, $subject)->select('notes.id');
        $rows = DB::table('audit_rollups as rollups')
            ->join('notes', function (JoinClause $join): void {
                $join->on('notes.id', '=', DB::raw('CAST(rollups.dimension_key AS UNSIGNED)'));
            })
            ->where('rollups.workspace_id', $workspace->id)
            ->where('rollups.dimension', RollupDimension::NOTE->value)
            ->whereBetween('rollups.period_start', [$from->toDateString(), $to->toDateString()])
            ->whereIn('notes.id', $visibleNotes)
            ->groupBy('notes.id', 'notes.path', 'notes.title')
            ->orderByDesc('count')
            ->orderBy('notes.id')
            ->limit($limit)
            ->get([
                'notes.id as note_id',
                'notes.path',
                'notes.title',
                DB::raw('SUM(rollups.count) as count'),
                DB::raw('MAX(rollups.last_seen_at) as last_seen_at'),
            ]);

        return $rows->map(fn ($row): array => [
            'note_id' => (int) $row->note_id,
            'path' => $row->path,
            'title' => $row->title,
            'count' => (int) $row->count,
            'last_seen_at' => $row->last_seen_at === null ? null : Carbon::parse($row->last_seen_at)->toIso8601String(),
        ])->all();
    }

    /** @return list<array{period_start: string, count: int}> */
    private function activityOverTime(Workspace $workspace, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $counts = AuditRollup::query()
            ->where('workspace_id', $workspace->id)
            ->where('dimension', RollupDimension::EVENT->value)
            ->whereBetween('period_start', [$from->toDateString(), $to->toDateString()])
            ->select('period_start', DB::raw('SUM(count) as count'))
            ->groupBy('period_start')
            ->pluck('count', 'period_start');

        $result = [];
        for ($day = $from; $day->lte($to); $day = $day->addDay()) {
            $date = $day->toDateString();
            $result[] = [
                'period_start' => $date,
                'count' => (int) ($counts[$date] ?? 0),
            ];
        }

        return $result;
    }

    /** @return list<array{actor_subject_id: string, count: int}> */
    private function activityByUser(Workspace $workspace, CarbonImmutable $from, CarbonImmutable $to, int $limit): array
    {
        return AuditRollup::query()
            ->where('workspace_id', $workspace->id)
            ->where('dimension', RollupDimension::ACTOR->value)
            ->whereBetween('period_start', [$from->toDateString(), $to->toDateString()])
            ->select('dimension_key', DB::raw('SUM(count) as count'))
            ->groupBy('dimension_key')
            ->orderByDesc('count')
            ->orderBy('dimension_key')
            ->limit($limit)
            ->get()
            ->map(fn ($row): array => [
                'actor_subject_id' => (string) $row->dimension_key,
                'count' => (int) $row->count,
            ])->all();
    }

    /** @return list<array<string, mixed>> */
    private function staleNotes(Workspace $workspace, AuthenticatedSubject $subject, int $limit): array
    {
        $staleDays = max(1, (int) config('jotter.analytics.stale_days', 30));
        $cutoff = CarbonImmutable::now('UTC')->subDays($staleDays);
        $now = CarbonImmutable::now('UTC');
        $notes = $this->visibleNotes($workspace, $subject)
            ->where('notes.updated_at', '<', $cutoff)
            ->orderBy('notes.updated_at')
            ->limit($limit)
            ->get(['notes.id', 'notes.path', 'notes.title', 'notes.updated_at']);

        return $notes->map(function (Note $note) use ($now): array {
            $updatedAt = Carbon::parse($note->updated_at);

            return [
                'note_id' => $note->id,
                'path' => $note->path,
                'title' => $note->title,
                'updated_at' => $updatedAt->toIso8601String(),
                'days_stale' => (int) $updatedAt->diffInDays($now),
            ];
        })->all();
    }

    private function visibleNotes(Workspace $workspace, AuthenticatedSubject $subject): \Illuminate\Database\Eloquent\Builder
    {
        $query = Note::query()->whereNull('notes.deleted_at');

        return $this->noteAccess->scopeVisible($query, $subject, $workspace->id);
    }
}
