<?php

namespace App\Http\Controllers;

use App\Support\ReleaseVersion;
use App\Support\SchedulerHeartbeat;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Unauthenticated liveness probe for one installation. It intentionally exposes
 * only the release version and the scheduler heartbeat: no hostnames, paths,
 * database names, instance slug, or configuration values.
 */
final class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $databaseReachable = $this->databaseReachable();

        return response()
            ->json([
                'status' => $databaseReachable ? 'ok' : 'unavailable',
                'version' => ReleaseVersion::current(),
                'scheduler_last_run_at' => SchedulerHeartbeat::lastRunAt()?->toIso8601String(),
            ], $databaseReachable ? 200 : 503)
            ->header('Cache-Control', 'no-store');
    }

    private function databaseReachable(): bool
    {
        try {
            DB::connection()->select('select 1');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
