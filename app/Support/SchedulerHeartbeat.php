<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Records when `php artisan schedule:run` last executed so the doctor command
 * and the health endpoint can tell whether the per-installation cron is alive.
 */
final class SchedulerHeartbeat
{
    public const CACHE_KEY = 'jotter:scheduler:last_run_at';

    public static function record(): void
    {
        Cache::forever(self::CACHE_KEY, CarbonImmutable::now()->toIso8601String());
    }

    public static function lastRunAt(): ?CarbonImmutable
    {
        try {
            $value = Cache::get(self::CACHE_KEY);
        } catch (\Throwable) {
            return null;
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
