<?php

namespace Tests\Feature;

use App\Models\MachineToken;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class McpTransportAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['jotter.auth_bypass' => false]);
        config(['jotter.auth_provider' => 'local']);
    }

    public function test_unauthenticated_call_is_rejected(): void
    {
        $res = $this->postJson('/api/mcp', [
            'jsonrpc' => '2.0',
            'method' => 'initialize',
            'id' => 1,
        ]);

        $res->assertStatus(401);
        $this->assertDatabaseHas('audit_log', ['event' => 'mcp.auth_failed']);
    }

    public function test_valid_machine_token_authenticates_and_completes_handshake(): void
    {
        $tenant = Tenant::create(['slug' => 'default', 'name' => 'Default Tenant']);
        $user = User::factory()->create(['is_admin' => true, 'is_active' => true]);

        $plainToken = 'mcp_test_token_12345';
        $tokenRecord = MachineToken::create([
            'tenant_id' => $tenant->id,
            'subject_id' => (string) $user->id,
            'name' => 'Test Agent Token',
            'token_hash' => MachineToken::hashToken($plainToken),
        ]);

        // Hashed storage assertion
        $this->assertNotEquals($plainToken, $tokenRecord->token_hash);
        $this->assertEquals(hash('sha256', $plainToken), $tokenRecord->token_hash);

        // Handshake request
        $res = $this->withHeader('Authorization', 'Bearer '.$plainToken)
            ->postJson('/api/mcp', [
                'jsonrpc' => '2.0',
                'method' => 'initialize',
                'id' => 1,
            ]);

        $res->assertOk();
        $res->assertJsonPath('result.serverInfo.name', 'Jotter MCP Server');
        $this->assertDatabaseHas('audit_log', [
            'event' => 'mcp.method_called',
            'actor_subject_id' => (string) $user->id,
        ]);
    }

    public function test_revoked_token_is_rejected_immediately(): void
    {
        $tenant = Tenant::create(['slug' => 'default', 'name' => 'Default Tenant']);
        $user = User::factory()->create(['is_admin' => true, 'is_active' => true]);

        $plainToken = 'mcp_revoked_token_999';
        $tokenRecord = MachineToken::create([
            'tenant_id' => $tenant->id,
            'subject_id' => (string) $user->id,
            'name' => 'Revoked Token',
            'token_hash' => MachineToken::hashToken($plainToken),
            'revoked_at' => now(),
        ]);

        $res = $this->withHeader('Authorization', 'Bearer '.$plainToken)
            ->postJson('/api/mcp', [
                'jsonrpc' => '2.0',
                'method' => 'initialize',
                'id' => 1,
            ]);

        $res->assertStatus(401);
    }
}
