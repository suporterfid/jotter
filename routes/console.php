<?php

use App\Support\SchedulerHeartbeat;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Periodic work (shared hosting: one cron entry per installation)
|--------------------------------------------------------------------------
|
| Every recurring job Jotter needs is registered here and driven by a single
| `php artisan schedule:run` cron entry executing every minute. Nothing below
| depends on a queue worker, daemon, or `runInBackground()` (which would fork
| a process). `withoutOverlapping()` uses the cache lock table, so a slow run
| never stacks up behind itself. Keep docs/deployment.md's job table in sync.
|
*/

// Heartbeat read by `jotter:doctor` and GET /api/health.
Schedule::call(static fn () => SchedulerHeartbeat::record())
    ->name('jotter:scheduler-heartbeat')
    ->everyMinute();

// Every minute: bounded, idempotent executors for dispatched work.
Schedule::command('notifications:send-digest', ['--limit=100'])->everyMinute()->withoutOverlapping(10);
Schedule::command('notifications:process-deliveries', ['--limit=50'])->everyMinute()->withoutOverlapping(10);
Schedule::command('pdf:process-exports')->everyMinute()->withoutOverlapping(10);

// Every five minutes: analytics rollups over new audit rows.
Schedule::command('analytics:rollup')->everyFiveMinutes()->withoutOverlapping(10);

// Hourly: reconcile the on-disk vaults into the MySQL projection.
Schedule::command('vault:reindex', ['--all'])->hourly()->withoutOverlapping(55);

// Nightly retention (spread out so they never share a minute).
Schedule::command('vault:purge-trash')->dailyAt('02:00')->withoutOverlapping(60);
Schedule::command('vault:prune-revisions', ['--days=30'])->dailyAt('02:15')->withoutOverlapping(60);
Schedule::command('audit:prune', ['--days=90'])->dailyAt('02:30')->withoutOverlapping(60);

// Hosted mode only: trials past their deadline become read_only (audited). A
// self-hosted installation has no trial tenants, so this is a no-op there.
Schedule::command('tenant:expire-trials')->dailyAt('03:00')->withoutOverlapping(60);
