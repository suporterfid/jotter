<?php

namespace Tests\Feature;

use App\Support\SchedulerHeartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class HealthEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_is_public_and_reports_version_and_scheduler_heartbeat(): void
    {
        Cache::forever(SchedulerHeartbeat::CACHE_KEY, '2026-08-31T10:00:00+00:00');

        $response = $this->getJson('/api/health');

        $response->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson([
                'status' => 'ok',
                'version' => null,
                'scheduler_last_run_at' => '2026-08-31T10:00:00+00:00',
            ]);
    }

    public function test_health_omits_sensitive_details(): void
    {
        config(['jotter.instance_slug' => 'acme']);

        $payload = $this->getJson('/api/health')->assertOk()->json();

        $this->assertSame(['status', 'version', 'scheduler_last_run_at'], array_keys($payload));
        $this->assertStringNotContainsString('acme', json_encode($payload));
    }
}
