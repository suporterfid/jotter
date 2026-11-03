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

    public function test_admin_issues_a_scoped_token_and_listing_shows_its_scope(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create();
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);
        $workspace = Workspace::create(['tenant_id' => $tenant->id, 'slug' => 'brain', 'name' => 'Brain', 'vault_path' => storage_path('app/vaults/tok_'.uniqid())]);
        Membership::create(['tenant_id' => $tenant->id, 'workspace_id' => $workspace->id, 'subject_id' => (string) $user->id, 'role' => 'editor']);

        $created = $this->actingAs($admin)->postJson('/api/admin/machine-tokens', [
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'name' => 'Hermes VPS',
            'scopes' => ['mcp:read', 'mcp:write'],
            'workspace_ids' => [$workspace->id],
            'write_paths' => [['glob' => 'inbox/{client}/**', 'ops' => ['create', 'append', 'update']], ['glob' => 'diario/**', 'ops' => ['append']]],
            'client_slug' => 'hermes',
            'expires' => '180d',
        ])->assertCreated();

        $created->assertJsonPath('data.scopes', ['mcp:read', 'mcp:write'])
            ->assertJsonPath('data.workspace_ids', [$workspace->id])
            ->assertJsonPath('data.client_slug', 'hermes')
            ->assertJsonPath('data.write_paths.1.glob', 'diario/**');
        $this->assertNotNull($created->json('data.expires_at'));

        $audit = AuditLog::query()->where('event', 'machine_token.created')->sole();
        $this->assertSame(['mcp:read', 'mcp:write'], $audit->metadata['scopes']);
        $this->assertSame('hermes', $audit->metadata['client_slug']);

        $this->actingAs($admin)->postJson('/api/admin/machine-tokens', [
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'name' => 'bad',
            'scopes' => ['mcp:read', 'mcp:write'], 'write_paths' => [['glob' => '_brain/**', 'ops' => ['update']]],
        ])->assertStatus(422)->assertJsonValidationErrors(['write_paths']);

        $this->actingAs($admin)->postJson('/api/admin/machine-tokens', [
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'name' => 'bad', 'scopes' => ['root'],
        ])->assertStatus(422)->assertJsonValidationErrors(['scopes']);
    }

    public function test_mcp_token_command_accepts_scope_options(): void
    {
        $admin = User::factory()->create(['email' => 'joe@acme.example', 'is_admin' => true]);
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);
        Workspace::create(['tenant_id' => $tenant->id, 'slug' => 'brain', 'name' => 'Brain', 'vault_path' => storage_path('app/vaults/tok_'.uniqid())]);

        $this->artisan('mcp:token joe@acme.example --tenant=acme')->assertFailed();

        $this->assertSame(0, Artisan::call('mcp:token', [
            'email' => 'joe@acme.example',
            '--tenant' => 'acme',
            '--name' => 'Hermes VPS',
            '--workspace' => ['brain'],
            '--scope' => ['mcp:read', 'mcp:write'],
            '--write-path' => ['inbox/{client}/**:create,append,update'],
            '--client' => 'hermes',
            '--expires' => '180d',
            '--json' => true,
        ]));
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(['mcp:read', 'mcp:write'], $report['scopes']);
        $this->assertSame(['brain'], $report['workspaces']);
        $this->assertSame('hermes', $report['client']);
        $this->assertSame([['glob' => 'inbox/{client}/**', 'ops' => ['create', 'append', 'update']]], $report['write_paths']);
        $this->assertNotNull($report['expires_at']);

        $this->artisan('mcp:token joe@acme.example --tenant=acme --workspace=brain --write-path="inbox/**:create"')->assertFailed();
    }
}
