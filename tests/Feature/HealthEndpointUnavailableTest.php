<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Deliberately without RefreshDatabase: the test swaps the default connection to
 * an unreachable host, and the trait would try to roll back on that connection.
 */
final class HealthEndpointUnavailableTest extends TestCase
{
    public function test_health_returns_503_when_the_database_is_unreachable(): void
    {
        config([
            'database.connections.unreachable' => array_merge(
                config('database.connections.mysql'),
                ['host' => '127.0.0.1', 'port' => 1, 'database' => 'unreachable'],
            ),
            'database.default' => 'unreachable',
        ]);
        DB::purge('unreachable');

        $response = $this->getJson('/api/health');

        $response->assertStatus(503)
            ->assertJsonPath('status', 'unavailable')
            ->assertJsonStructure(['status', 'version', 'scheduler_last_run_at']);
    }
}
