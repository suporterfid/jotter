<?php

namespace Tests\Feature;

use App\Domain\Vault\VaultStorage;
use App\Models\MachineToken;
use App\Models\Membership;
use App\Models\Note;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class McpReadOnlyToolsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['jotter.auth_bypass' => false]);
        config(['jotter.auth_provider' => 'local']);
    }

    public function test_read_only_mcp_tools_and_cross_workspace_denial(): void
    {
        $tenant = Tenant::create(['slug' => 'default', 'name' => 'Default']);
        
        $workspaceA = Workspace::create([
            'tenant_id' => $tenant->id,
            'slug' => 'ws-a',
            'name' => 'Workspace A',
            'vault_path' => storage_path('app/vaults/mcp_a_'.uniqid()),
        ]);

        $workspaceB = Workspace::create([
            'tenant_id' => $tenant->id,
            'slug' => 'ws-b',
            'name' => 'Workspace B',
            'vault_path' => storage_path('app/vaults/mcp_b_'.uniqid()),
        ]);

        $user = User::factory()->create(['is_admin' => false, 'is_active' => true]);

        // User is member ONLY of Workspace A
        Membership::create([
            'tenant_id' => $tenant->id,
            'workspace_id' => $workspaceA->id,
            'subject_id' => (string) $user->id,
            'role' => 'editor',
        ]);

        $plainToken = 'mcp_token_ws_a';
        MachineToken::create([
            'tenant_id' => $tenant->id,
            'subject_id' => (string) $user->id,
            'name' => 'WS-A Agent',
            'token_hash' => MachineToken::hashToken($plainToken),
        ]);

        // 1. Authorized call on Workspace A succeeds
        $listRes = $this->withHeader('Authorization', 'Bearer '.$plainToken)
            ->postJson('/api/mcp', [
                'jsonrpc' => '2.0',
                'method' => 'tools/call',
                'params' => [
                    'name' => 'list_notes',
                    'arguments' => ['workspace_id' => $workspaceA->id],
                ],
                'id' => 1,
            ]);
        $listRes->assertOk();

        // 2. Unauthorized call on Workspace B is denied (403 / error -32003) per tool
        $tools = ['list_notes', 'read_note', 'search_notes', 'get_backlinks'];
        foreach ($tools as $tool) {
            $deniedRes = $this->withHeader('Authorization', 'Bearer '.$plainToken)
                ->postJson('/api/mcp', [
                    'jsonrpc' => '2.0',
                    'method' => 'tools/call',
                    'params' => [
                        'name' => $tool,
                        'arguments' => ['workspace_id' => $workspaceB->id],
                    ],
                    'id' => 1,
                ]);
            $deniedRes->assertStatus(403);
            $deniedRes->assertJsonPath('error.code', -32003);
        }

        // 3. read_note on an existing, authorized note returns its actual content
        $storage = $this->app->make(VaultStorage::class);
        $storage->write($workspaceA, 'mcp-read-note.md', "# MCP Read Note\nActual content.");

        $readRes = $this->withHeader('Authorization', 'Bearer '.$plainToken)
            ->postJson('/api/mcp', [
                'jsonrpc' => '2.0',
                'method' => 'tools/call',
                'params' => [
                    'name' => 'read_note',
                    'arguments' => ['workspace_id' => $workspaceA->id, 'path' => 'mcp-read-note.md'],
                ],
                'id' => 1,
            ]);
        $readRes->assertOk();
        $readRes->assertJsonPath('result.content.0.text', "# MCP Read Note\nActual content.");
    }
}
