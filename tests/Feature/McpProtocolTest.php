<?php

namespace Tests\Feature;

use App\Models\MachineToken;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Wire-level behaviour real MCP clients (Claude Code, Cursor, mcp-remote)
 * depend on: version negotiation, notifications, ping, tool schemas, and the
 * workspace default.
 */
final class McpProtocolTest extends TestCase
{
    use RefreshDatabase;

    private string $token = 'jt_mkt_protocol_test';

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['jotter.auth_bypass' => false, 'jotter.auth_provider' => 'local']);

        $this->tenant = Tenant::create(['slug' => 'mcp', 'name' => 'MCP']);
        $this->user = User::factory()->create(['is_admin' => false]);
        MachineToken::create(['tenant_id' => $this->tenant->id, 'subject_id' => (string) $this->user->id, 'name' => 'test', 'token_hash' => MachineToken::hashToken($this->token)]);
    }

    private function workspace(string $slug): Workspace
    {
        $workspace = Workspace::create(['tenant_id' => $this->tenant->id, 'slug' => $slug, 'name' => ucfirst($slug), 'vault_path' => storage_path('app/vaults/mcp_'.$slug.'_'.uniqid())]);
        Membership::create(['tenant_id' => $this->tenant->id, 'workspace_id' => $workspace->id, 'subject_id' => (string) $this->user->id, 'role' => 'editor']);

        return $workspace;
    }

    private function rpc(array $payload)
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->withHeader('Accept', 'application/json, text/event-stream')
            ->postJson('/api/mcp', $payload + ['jsonrpc' => '2.0']);
    }

    public function test_initialize_echoes_a_supported_protocol_version_and_falls_back_to_the_newest(): void
    {
        $this->rpc(['id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-03-26', 'capabilities' => [], 'clientInfo' => ['name' => 'test', 'version' => '1']]])
            ->assertOk()
            ->assertJsonPath('result.protocolVersion', '2025-03-26')
            ->assertJsonPath('result.serverInfo.name', 'Jotter MCP Server')
            ->assertJsonPath('id', 1);

        $this->rpc(['id' => 2, 'method' => 'initialize', 'params' => ['protocolVersion' => '1999-01-01']])
            ->assertOk()
            ->assertJsonPath('result.protocolVersion', '2025-06-18');
    }

    public function test_notifications_are_acknowledged_with_202_and_ping_returns_an_empty_result(): void
    {
        $this->rpc(['method' => 'notifications/initialized'])->assertStatus(202);
        $this->assertSame('', $this->rpc(['method' => 'notifications/initialized'])->getContent());

        $this->rpc(['id' => 'p1', 'method' => 'ping'])->assertOk()->assertJsonPath('id', 'p1')->assertJson(['result' => []]);
        $this->rpc(['id' => 3, 'method' => 'resources/list'])->assertOk()->assertJsonPath('result.resources', []);
        $this->rpc(['id' => 4, 'method' => 'prompts/list'])->assertOk()->assertJsonPath('result.prompts', []);
    }

    public function test_tools_list_publishes_input_schemas(): void
    {
        $tools = $this->rpc(['id' => 5, 'method' => 'tools/list'])->assertOk()->json('result.tools');

        $this->assertSame(['list_workspaces', 'list_notes', 'read_note', 'search_notes', 'get_backlinks'], array_column($tools, 'name'));
        foreach ($tools as $tool) {
            $this->assertSame('object', $tool['inputSchema']['type'], $tool['name']);
            $this->assertNotEmpty($tool['description']);
        }
        $byName = array_column($tools, null, 'name');
        $this->assertSame(['query'], $byName['search_notes']['inputSchema']['required']);
        $this->assertSame(['path'], $byName['read_note']['inputSchema']['required']);
        $this->assertArrayHasKey('workspace_id', $byName['list_notes']['inputSchema']['properties']);
    }

    public function test_workspace_id_defaults_when_the_token_reaches_exactly_one_workspace(): void
    {
        $workspace = $this->workspace('only');
        Workspace::create(['tenant_id' => $this->tenant->id, 'slug' => 'foreign', 'name' => 'Foreign', 'vault_path' => storage_path('app/vaults/mcp_foreign_'.uniqid())]);

        $list = $this->rpc(['id' => 6, 'method' => 'tools/call', 'params' => ['name' => 'list_workspaces', 'arguments' => []]])->assertOk();
        $this->assertSame([['id' => $workspace->id, 'slug' => 'only', 'name' => 'Only']], json_decode($list->json('result.content.0.text'), true));

        $this->rpc(['id' => 7, 'method' => 'tools/call', 'params' => ['name' => 'search_notes', 'arguments' => ['query' => 'anything']]])
            ->assertOk()
            ->assertJsonMissingPath('result.isError');
    }

    public function test_workspace_id_is_demanded_with_a_recoverable_tool_error_when_ambiguous(): void
    {
        $a = $this->workspace('alpha');
        $b = $this->workspace('beta');

        $response = $this->rpc(['id' => 8, 'method' => 'tools/call', 'params' => ['name' => 'list_notes', 'arguments' => []]])->assertOk();

        $this->assertTrue($response->json('result.isError'));
        $this->assertStringContainsString("{$a->id} (alpha)", $response->json('result.content.0.text'));
        $this->assertStringContainsString("{$b->id} (beta)", $response->json('result.content.0.text'));
    }

    public function test_unknown_tool_and_unknown_method_return_json_rpc_errors(): void
    {
        $this->workspace('one');
        $this->rpc(['id' => 9, 'method' => 'tools/call', 'params' => ['name' => 'delete_everything', 'arguments' => []]])->assertStatus(404)->assertJsonPath('error.code', -32602);
        $this->rpc(['id' => 10, 'method' => 'nope'])->assertStatus(404)->assertJsonPath('error.code', -32601);
    }
}
