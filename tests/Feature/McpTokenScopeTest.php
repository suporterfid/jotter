<?php

namespace Tests\Feature;

use App\Domain\Auth\MachineTokenIssuer;
use App\Domain\Auth\MachineTokenOptions;
use App\Domain\Vault\VaultStorage;
use App\Models\AuditLog;
use App\Models\Membership;
use App\Models\Note;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Scoped credentials (brain multi-agent plan, PR 1): a token's scopes decide
 * which routes it may call. A read-only MCP token can no longer write through
 * REST or WebDAV, and no token ever reaches admin/auth/user routes.
 */
final class McpTokenScopeTest extends TestCase
{
    use RefreshDatabase;

    private string $vaultRoot;
    private Tenant $tenant;
    private Workspace $workspace;
    private Note $note;
    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();
        config(['jotter.auth_bypass' => false, 'jotter.auth_provider' => 'local']);

        $this->vaultRoot = sys_get_temp_dir().'/jotter-token-scope-'.uniqid('', true);
        File::ensureDirectoryExists($this->vaultRoot);

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);
        $this->workspace = Workspace::create(['tenant_id' => $this->tenant->id, 'slug' => 'brain', 'name' => 'Brain', 'vault_path' => $this->vaultRoot]);
        $this->editor = User::factory()->create(['is_admin' => false]);
        Membership::create(['tenant_id' => $this->tenant->id, 'workspace_id' => $this->workspace->id, 'subject_id' => (string) $this->editor->id, 'role' => 'editor']);
        $this->note = app(VaultStorage::class)->write($this->workspace, 'note.md', "# Original\n");
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->vaultRoot);
        parent::tearDown();
    }

    /**
     * @param list<string> $scopes
     */
    private function token(array $scopes, ?User $owner = null, ?array $workspaces = null): string
    {
        $owner ??= $this->editor;
        $writePaths = in_array('mcp:write', $scopes, true) ? ['inbox/**:create'] : [];
        $options = MachineTokenOptions::fromInput($owner, $this->tenant, $scopes, $workspaces, $writePaths);

        return app(MachineTokenIssuer::class)->issue($owner, $this->tenant, 'scope test', $options)['plain'];
    }

    public function test_read_only_mcp_token_cannot_write_through_rest(): void
    {
        $plain = $this->token(['mcp:read']);

        $this->withToken($plain)
            ->putJson("/api/workspaces/{$this->workspace->id}/notes/{$this->note->id}", ['content' => "# Hijacked\n"])
            ->assertStatus(403)
            ->assertHeader('WWW-Authenticate', 'Bearer error="insufficient_scope", scope="api:write"');

        $this->assertSame("# Original\n", file_get_contents($this->vaultRoot.'/note.md'));
        $forbidden = AuditLog::query()->where('event', 'auth.forbidden')->latest('id')->firstOrFail();
        $this->assertSame('insufficient_scope', $forbidden->metadata['reason']);
        $this->assertSame('api:write', $forbidden->metadata['required_scope']);
        $this->assertArrayHasKey('token_id', $forbidden->metadata);
    }

    public function test_read_only_mcp_token_cannot_write_through_webdav(): void
    {
        $plain = $this->token(['mcp:read']);

        $this->withToken($plain)
            ->call('PUT', "/api/webdav/{$this->workspace->id}/note.md", [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$plain], "# Hijacked\n")
            ->assertStatus(403);

        $this->assertSame("# Original\n", file_get_contents($this->vaultRoot.'/note.md'));
    }

    public function test_read_only_mcp_token_cannot_read_through_rest(): void
    {
        $plain = $this->token(['mcp:read']);

        $this->withToken($plain)
            ->getJson("/api/workspaces/{$this->workspace->id}/notes")
            ->assertStatus(403)
            ->assertHeader('WWW-Authenticate', 'Bearer error="insufficient_scope", scope="api:read"');
    }

    public function test_api_read_allows_reads_but_not_writes(): void
    {
        $plain = $this->token(['api:read']);

        $this->withToken($plain)->getJson("/api/workspaces/{$this->workspace->id}/notes")->assertOk();
        $this->withToken($plain)
            ->putJson("/api/workspaces/{$this->workspace->id}/notes/{$this->note->id}", ['content' => "# Nope\n"])
            ->assertStatus(403);
        $this->withToken($plain)
            ->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])
            ->assertStatus(403)
            ->assertHeader('WWW-Authenticate', 'Bearer error="insufficient_scope", scope="mcp:read"');
    }

    public function test_api_write_lets_an_editor_token_write_through_rest(): void
    {
        $plain = $this->token(['api:read', 'api:write']);

        $this->withToken($plain)
            ->putJson("/api/workspaces/{$this->workspace->id}/notes/{$this->note->id}", ['content' => "# Agent edit\n"])
            ->assertOk();

        $this->assertSame("# Agent edit\n", file_get_contents($this->vaultRoot.'/note.md'));
    }

    public function test_api_write_does_not_lift_a_viewer_role(): void
    {
        $viewer = User::factory()->create(['is_admin' => false]);
        Membership::create(['tenant_id' => $this->tenant->id, 'workspace_id' => $this->workspace->id, 'subject_id' => (string) $viewer->id, 'role' => 'viewer']);
        $plain = $this->token(['api:read', 'api:write'], $viewer);

        $this->withToken($plain)
            ->putJson("/api/workspaces/{$this->workspace->id}/notes/{$this->note->id}", ['content' => "# Viewer\n"])
            ->assertStatus(403);
    }

    public function test_no_token_reaches_admin_auth_or_user_routes(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $plain = $this->token(['mcp:read', 'mcp:write', 'api:read', 'api:write'], $admin, [$this->workspace->id]);

        $this->withToken($plain)->getJson('/api/admin/machine-tokens')->assertStatus(403);
        $this->withToken($plain)->postJson('/api/admin/users', ['email' => 'x@example.com'])->assertStatus(403);
        $this->withToken($plain)->getJson('/api/auth/me')->assertStatus(403);
        $this->withToken($plain)->postJson('/api/user/locale', ['locale' => 'en'])->assertStatus(403);

        $this->assertSame(4, AuditLog::query()->where('event', 'auth.forbidden')->get()
            ->filter(static fn (AuditLog $log): bool => ($log->metadata['reason'] ?? null) === 'credential_not_allowed')->count());
    }

    public function test_unknown_jotter_token_does_not_fall_back_to_the_session(): void
    {
        $this->actingAs($this->editor)
            ->withToken('jt_mkt_this-token-does-not-exist')
            ->getJson("/api/workspaces/{$this->workspace->id}/notes")
            ->assertStatus(401);
    }

    public function test_expired_token_is_rejected(): void
    {
        $plain = $this->token(['mcp:read']);
        \App\Models\MachineToken::query()->update(['expires_at' => now()->subMinute()]);

        $this->withToken($plain)
            ->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])
            ->assertStatus(401);
    }

    public function test_last_used_at_is_recorded(): void
    {
        $plain = $this->token(['mcp:read']);

        $this->withToken($plain)->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])->assertOk();

        $this->assertNotNull(\App\Models\MachineToken::query()->sole()->last_used_at);
    }
}
