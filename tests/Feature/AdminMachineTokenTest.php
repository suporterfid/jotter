<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\MachineToken;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AdminMachineTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_issues_lists_and_revokes_machine_tokens(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create();
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);
        $workspace = Workspace::create(['tenant_id' => $tenant->id, 'slug' => 'docs', 'name' => 'Docs', 'vault_path' => storage_path('app/vaults/tok_'.uniqid())]);
        Membership::create(['tenant_id' => $tenant->id, 'workspace_id' => $workspace->id, 'subject_id' => (string) $user->id, 'role' => 'viewer']);
        config(['app.url' => 'https://acme.example.com']);

        $created = $this->actingAs($admin)
            ->postJson('/api/admin/machine-tokens', ['tenant_id' => $tenant->id, 'user_id' => $user->id, 'name' => 'Ana — Claude Code'])
            ->assertCreated();

        $plain = $created->json('data.token');
        $this->assertStringStartsWith('jt_mkt_', $plain);
        $this->assertSame('https://acme.example.com/api/mcp', $created->json('data.mcp_url'));
        $this->assertSame($user->email, $created->json('data.user_email'));
        $this->assertSame(MachineToken::hashToken($plain), MachineToken::query()->sole()->token_hash);
        $this->assertStringNotContainsString($plain, json_encode(AuditLog::query()->get()->toArray()));

        // The token works against MCP as the user (only the workspace they belong to).
        $this->flushSession();
        $this->withHeader('Authorization', 'Bearer '.$plain)
            ->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'list_notes', 'arguments' => []]])
            ->assertOk()
            ->assertJsonMissingPath('result.isError');

        $this->flushHeaders();
        $list = $this->actingAs($admin)->getJson('/api/admin/machine-tokens')->assertOk();
        $this->assertNull($list->json('data.0.revoked_at'));
        $this->assertArrayNotHasKey('token', $list->json('data.0'));
        $this->assertArrayNotHasKey('token_hash', $list->json('data.0'));

        $id = $list->json('data.0.id');
        $this->flushHeaders();
        $this->actingAs($admin)->deleteJson("/api/admin/machine-tokens/{$id}")->assertOk();
        $this->assertNotNull(MachineToken::query()->findOrFail($id)->revoked_at);

        // actingAs() pins the guard user in memory; drop it so only the Bearer
        // token authenticates the next request.
        auth()->guard('web')->logout();
        auth()->forgetGuards();
        $this->flushSession();
        $this->withHeader('Authorization', 'Bearer '.$plain)
            ->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'ping'])
            ->assertStatus(401);
        $this->assertSame(1, AuditLog::query()->where('event', 'machine_token.revoked')->count());
    }

    public function test_non_admins_cannot_manage_machine_tokens(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);

        $this->actingAs($user)->getJson('/api/admin/machine-tokens')->assertStatus(403);
        $this->actingAs($user)->postJson('/api/admin/machine-tokens', ['tenant_id' => $tenant->id, 'user_id' => $user->id, 'name' => 'x'])->assertStatus(403);
    }

    public function test_mcp_token_command_issues_a_token_from_the_shell(): void
    {
        $user = User::factory()->create(['email' => 'ana@acme.example']);
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);
        $workspace = Workspace::create(['tenant_id' => $tenant->id, 'slug' => 'docs', 'name' => 'Docs', 'vault_path' => storage_path('app/vaults/tok_'.uniqid())]);
        Membership::create(['tenant_id' => $tenant->id, 'workspace_id' => $workspace->id, 'subject_id' => (string) $user->id, 'role' => 'owner']);

        $this->assertSame(0, Artisan::call('mcp:token', ['email' => 'ANA@acme.example', '--name' => 'Cursor', '--json' => true]));
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('acme', $report['tenant']);
        $this->assertStringStartsWith('jt_mkt_', $report['token']);
        $this->assertSame(1, MachineToken::query()->where('name', 'Cursor')->count());

        $this->artisan('mcp:token missing@acme.example')->assertFailed();
        Tenant::create(['slug' => 'other', 'name' => 'Other']);
        $this->artisan('mcp:token ana@acme.example --tenant=other')->assertSuccessful();
        $this->artisan('mcp:token ana@acme.example --tenant=ghost')->assertFailed();
    }
}
