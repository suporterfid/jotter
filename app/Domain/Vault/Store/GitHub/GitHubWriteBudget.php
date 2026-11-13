<?php

namespace App\Domain\Vault\Store\GitHub;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Write token bucket per actor (plan §5.7): 400/h and 60/min by default,
 * below GitHub's 500/h and 80/min for content-creating requests. A
 * `retry-after` or an exhausted `x-ratelimit-remaining` from GitHub blocks the
 * actor until then and wins over the local count. Running out never fails a
 * user's save: vault:flush only waits.
 */
class GitHubWriteBudget
{
    private const TABLE = 'github_write_budgets';

    /** Writes available now (the smaller of both buckets), after refilling. */
    public function available(string $actorKey): int
    {
        $row = $this->refilled($actorKey);
        if ($row['blocked_until'] !== null && $row['blocked_until']->isFuture()) {
            return 0;
        }

        return (int) floor(min($row['hour_tokens'], $row['minute_tokens']));
    }

    /** Seconds until $writes are available (0 = now). */
    public function waitFor(string $actorKey, int $writes): int
    {
        $row = $this->refilled($actorKey);
        $wait = 0;
        if ($row['blocked_until'] !== null && $row['blocked_until']->isFuture()) {
            $wait = (int) ceil(now()->diffInSeconds($row['blocked_until'], true));
        }

        $perHour = max(1, $this->perHour());
        $perMinute = max(1, $this->perMinute());
        $writes = min($writes, $perMinute, $perHour);
        $hourMissing = max(0, $writes - $row['hour_tokens']);
        $minuteMissing = max(0, $writes - $row['minute_tokens']);

        return max($wait, (int) ceil($hourMissing * 3600 / $perHour), (int) ceil($minuteMissing * 60 / $perMinute));
    }

    /** @throws GitHubApiError (rate-limited) when the actor has no write left */
    public function assertAvailable(string $actorKey): void
    {
        if ($this->available($actorKey) < 1) {
            throw new GitHubApiError('GitHub write budget exhausted; the flush waits.', 429, max(1, $this->waitFor($actorKey, 1)));
        }
    }

    public function consume(string $actorKey, int $writes): void
    {
        $this->refilled($actorKey);
        DB::table(self::TABLE)->where('actor_key', $actorKey)->update([
            'hour_tokens' => DB::raw('GREATEST(0, hour_tokens - '.(int) $writes.')'),
            'minute_tokens' => DB::raw('GREATEST(0, minute_tokens - '.(int) $writes.')'),
            'updated_at' => now(),
        ]);
    }

    public function block(string $actorKey, int $seconds): void
    {
        $this->refilled($actorKey);
        $until = now()->addSeconds(max(1, $seconds));
        DB::table(self::TABLE)->where('actor_key', $actorKey)
            ->where(fn ($query) => $query->whereNull('blocked_until')->orWhere('blocked_until', '<', $until))
            ->update(['blocked_until' => $until, 'updated_at' => now()]);
    }

    public function perHour(): int
    {
        return max(1, (int) config('jotter.github.writes_per_hour', 400));
    }

    public function perMinute(): int
    {
        return max(1, (int) config('jotter.github.writes_per_minute', 60));
    }

    /**
     * @return array{hour_tokens: float, minute_tokens: float, blocked_until: ?Carbon}
     */
    private function refilled(string $actorKey): array
    {
        $now = now();
        $row = DB::table(self::TABLE)->where('actor_key', $actorKey)->first();
        if ($row === null) {
            DB::table(self::TABLE)->insertOrIgnore([
                'actor_key' => $actorKey,
                'hour_tokens' => $this->perHour(),
                'minute_tokens' => $this->perMinute(),
                'refilled_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $row = DB::table(self::TABLE)->where('actor_key', $actorKey)->first();
        }

        $elapsed = max(0, $now->getTimestamp() - Carbon::parse($row->refilled_at ?? $now)->getTimestamp());
        $hour = min($this->perHour(), (float) $row->hour_tokens + $elapsed * $this->perHour() / 3600);
        $minute = min($this->perMinute(), (float) $row->minute_tokens + $elapsed * $this->perMinute() / 60);

        if ($elapsed > 0) {
            DB::table(self::TABLE)->where('actor_key', $actorKey)->update([
                'hour_tokens' => $hour,
                'minute_tokens' => $minute,
                'refilled_at' => $now,
            ]);
        }

        return [
            'hour_tokens' => $hour,
            'minute_tokens' => $minute,
            'blocked_until' => $row->blocked_until === null ? null : Carbon::parse($row->blocked_until),
        ];
    }
}
