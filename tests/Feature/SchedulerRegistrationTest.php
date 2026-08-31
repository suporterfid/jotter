<?php

namespace Tests\Feature;

use App\Support\SchedulerHeartbeat;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Shared hosting runs one cron entry per installation: `schedule:run` every
 * minute. Every periodic job must therefore be registered with the scheduler
 * and none may rely on a queue worker or a background process.
 */
final class SchedulerRegistrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return list<string>
     */
    private function scheduledCommands(): array
    {
        return array_values(array_filter(array_map(
            static fn (Event $event): string => (string) ($event->command ?? $event->description ?? ''),
            app(Schedule::class)->events(),
        )));
    }

    public function test_every_periodic_job_is_registered_with_the_scheduler(): void
    {
        $commands = implode("\n", $this->scheduledCommands());

        foreach ([
            'notifications:send-digest',
            'notifications:process-deliveries',
            'pdf:process-exports',
            'analytics:rollup',
            'vault:reindex',
            'vault:purge-trash',
            'vault:prune-revisions',
            'audit:prune',
            'tenant:expire-trials',
            'jotter:scheduler-heartbeat',
        ] as $expected) {
            $this->assertStringContainsString($expected, $commands, "{$expected} is not scheduled");
        }
    }

    public function test_no_scheduled_job_runs_in_background(): void
    {
        foreach (app(Schedule::class)->events() as $event) {
            $this->assertFalse($event->runInBackground, 'Scheduled jobs must not fork background processes on shared hosting.');
        }
    }

    public function test_schedule_run_records_the_heartbeat(): void
    {
        Cache::forget(SchedulerHeartbeat::CACHE_KEY);

        $this->artisan('schedule:run')->assertSuccessful();

        $this->assertNotNull(SchedulerHeartbeat::lastRunAt());
        $this->assertTrue(SchedulerHeartbeat::lastRunAt()->greaterThan(now()->subMinute()));
    }
}
