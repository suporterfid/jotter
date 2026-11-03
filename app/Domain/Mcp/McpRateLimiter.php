<?php

namespace App\Domain\Mcp;

use App\Domain\Auth\AuthenticatedSubject;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Per-credential MCP limits (config `jotter.mcp.rate_limits`): calls per
 * minute for every method, plus writes per minute and per day for the write
 * tools. Keys use the token id, or the subject id for session callers and
 * GrandpaSSOn service tokens. Uses the default cache store (database on
 * shared hosting), so no daemon is involved.
 */
final class McpRateLimiter
{
    private const MINUTE = 60;
    private const DAY = 86400;

    /**
     * Counts one MCP call. Returns null when allowed, or the seconds until a
     * retry is possible when the limit is exhausted.
     */
    public function hitCall(AuthenticatedSubject $subject): ?int
    {
        return $this->hit('mcp:calls:'.$this->key($subject), $this->limit('calls_per_minute', 120), self::MINUTE);
    }

    /**
     * Counts one write-tool call against the per-minute and per-day write limits.
     */
    public function hitWrite(AuthenticatedSubject $subject): ?int
    {
        $key = $this->key($subject);
        $perMinute = $this->limit('writes_per_minute', 30);
        $perDay = $this->limit('writes_per_day', 500);

        foreach ([['mcp:writes:'.$key, $perMinute, self::MINUTE], ['mcp:writes-day:'.$key, $perDay, self::DAY]] as [$name, $max]) {
            if (RateLimiter::tooManyAttempts($name, $max)) {
                return max(1, RateLimiter::availableIn($name));
            }
        }

        RateLimiter::hit('mcp:writes:'.$key, self::MINUTE);
        RateLimiter::hit('mcp:writes-day:'.$key, self::DAY);

        return null;
    }

    private function hit(string $name, int $max, int $decaySeconds): ?int
    {
        if (RateLimiter::tooManyAttempts($name, $max)) {
            return max(1, RateLimiter::availableIn($name));
        }

        RateLimiter::hit($name, $decaySeconds);

        return null;
    }

    private function key(AuthenticatedSubject $subject): string
    {
        return $subject->credential !== null
            ? 'token:'.$subject->credential->tokenId
            : 'subject:'.$subject->subjectId;
    }

    private function limit(string $name, int $default): int
    {
        return max(1, (int) config('jotter.mcp.rate_limits.'.$name, $default));
    }
}
