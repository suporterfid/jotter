<?php

namespace Tests\Feature;

use App\Domain\Auth\MachineTokenResolver;
use App\Domain\Mcp\McpRateLimiter;
use App\Models\AuditLog;
use App\Models\MachineToken;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class McpRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        config(['jotter.auth_bypass' => false, 'jotter.auth_provider' => 'local']);
        $this->tenant = Tenant::create(['slug' => 'rl', 'name' => 'Rate limit']);
    }

    private function token(string $plain): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        MachineToken::create(['tenant_id' => $this->tenant->id, 'subject_id' => (string) $user->id, 'name' => $plain, 'token_hash' => MachineToken::hashToken($plain)]);
    }

    private function ping(string $plain, int $id)
    {
        return $this->withToken($plain)->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => $id, 'method' => 'ping']);
    }

    public function test_the_121st_call_in_a_minute_gets_429_with_retry_after(): void
    {
        $this->token('jt_mkt_rate_a');
        $this->token('jt_mkt_rate_b');

        for ($i = 1; $i <= 120; $i++) {
            $this->ping('jt_mkt_rate_a', $i)->assertOk();
        }

        $limited = $this->ping('jt_mkt_rate_a', 121)->assertStatus(429);
        $retryAfter = (int) $limited->headers->get('Retry-After');
        $this->assertGreaterThanOrEqual(1, $retryAfter);
        $this->assertLessThanOrEqual(60, $retryAfter);
        $this->assertSame(-32000, $limited->json('error.code'));

        // Another token has its own budget.
        $this->ping('jt_mkt_rate_b', 1)->assertOk();

        $log = AuditLog::query()->where('event', 'mcp.rate_limited')->sole();
        $this->assertSame('calls_per_minute', $log->metadata['limit']);
    }

    public function test_notifications_do_not_consume_the_call_budget(): void
    {
        config(['jotter.mcp.rate_limits.calls_per_minute' => 2]);
        $this->token('jt_mkt_rate_n');

        for ($i = 0; $i < 5; $i++) {
            $this->withToken('jt_mkt_rate_n')->postJson('/api/mcp', ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'])->assertStatus(202);
        }
        $this->ping('jt_mkt_rate_n', 1)->assertOk();
        $this->ping('jt_mkt_rate_n', 2)->assertOk();
        $this->ping('jt_mkt_rate_n', 3)->assertStatus(429);
    }

    public function test_write_limits_apply_per_minute_and_per_day(): void
    {
        config(['jotter.mcp.rate_limits.writes_per_minute' => 2, 'jotter.mcp.rate_limits.writes_per_day' => 3]);
        $this->token('jt_mkt_rate_w');
        $subject = (new MachineTokenResolver)->resolve('jt_mkt_rate_w');
        $this->assertNotNull($subject);
        $limiter = new McpRateLimiter;

        $this->assertNull($limiter->hitWrite($subject));
        $this->assertNull($limiter->hitWrite($subject));
        $this->assertNotNull($limiter->hitWrite($subject), 'third write in a minute is limited');

        $this->travel(61)->seconds();
        $this->assertNull($limiter->hitWrite($subject));
        $this->travel(61)->seconds();
        $retry = $limiter->hitWrite($subject);
        $this->assertNotNull($retry, 'fourth write in a day is limited');
        $this->assertGreaterThan(60, $retry);
    }
}
