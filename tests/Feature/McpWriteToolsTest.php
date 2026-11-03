<?php

namespace Tests\Feature;

use App\Domain\Auth\MachineTokenIssuer;
use App\Domain\Auth\MachineTokenOptions;
use App\Domain\Vault\VaultStorage;
use App\Models\AuditLog;
use App\Models\Membership;
use App\Models\NoteAclEntry;
use App\Models\NoteRevision;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * MCP write tools (brain plan PR 3): writes stay inside the token's write
 * paths, never touch the disk when refused, and every write leaves a revision
 * and an `mcp.note_written` audit entry.
 */
final class McpWriteToolsTest extends TestCase
{
    use RefreshDatabase;

    private string $vaultRoot;
    private Tenant $tenant;
    private Workspace $workspace;
    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();
        config(['jotter.auth_bypass' => false, 'jotter.auth_provider' => 'local']);

        $this->vaultRoot = sys_get_temp_dir().'/jotter-mcp-write-'.uniqid('', true);
        File::ensureDirectoryExists($this->vaultRoot);
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);
        $this->workspace = Workspace::create(['tenant_id' => $this->tenant->id, 'slug' => 'brain', 'name' => 'Brain', 'vault_path' => $this->vaultRoot]);
        $this->editor = User::factory()->create(['is_admin' => false]);
        Membership::create(['tenant_id' => $this->tenant->id, 'workspace_id' => $this->workspace->id, 'subject_id' => (string) $this->editor->id, 'role' => 'editor']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->vaultRoot);
        parent::tearDown();
    }

    /**
     * @param list<string> $scopes
     * @param list<string> $writePaths
     */
    private function token(array $scopes = ['mcp:read', 'mcp:write'], array $writePaths = ['inbox/{client}/**:create,append,update', 'diario/**:append'], ?User $owner = null): string
    {
        $owner ??= $this->editor;
        $options = MachineTokenOptions::fromInput($owner, $this->tenant, $scopes, null, in_array('mcp:write', $scopes, true) ? $writePaths : [], 'hermes');

        return app(MachineTokenIssuer::class)->issue($owner, $this->tenant, 'writer', $options)['plain'];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function tool(string $plain, string $tool, array $arguments): TestResponse
    {
        return $this->withToken($plain)->postJson('/api/mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $arguments],
        ]);
    }

    private function file(string $relative): string
    {
        return $this->vaultRoot.'/'.$relative;
    }

    public function test_write_tools_are_listed_only_with_mcp_write(): void
    {
        $reader = $this->token(['mcp:read']);
        $writer = $this->token();

        $listed = fn (string $plain): array => array_column($this->withToken($plain)->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->json('result.tools'), 'name');

        $this->assertNotContains('create_note', $listed($reader));
        $this->assertContains('create_note', $listed($writer));
        $this->assertContains('append_to_note', $listed($writer));
        $this->assertContains('update_note', $listed($writer));

        $this->tool($reader, 'create_note', ['path' => 'inbox/hermes/x.md', 'content' => 'x'])->assertStatus(403);
        $this->assertFileDoesNotExist($this->file('inbox/hermes/x.md'));
    }

    public function test_create_note_writes_inside_the_client_inbox_with_revision_and_audit(): void
    {
        $plain = $this->token();

        $response = $this->tool($plain, 'create_note', ['path' => 'inbox/hermes/2026-11-03-teste.md', 'content' => "# Teste\n\nconexão ok\n"])->assertOk();

        $response->assertJsonMissingPath('result.isError')
            ->assertJsonPath('result.structuredContent.status', 'written')
            ->assertJsonPath('result.structuredContent.created', true)
            ->assertJsonPath('result.structuredContent.sha256', hash('sha256', "# Teste\n\nconexão ok\n"));
        $this->assertSame("# Teste\n\nconexão ok\n", file_get_contents($this->file('inbox/hermes/2026-11-03-teste.md')));
        $this->assertSame(1, NoteRevision::query()->count());
        $this->assertSame((string) $this->editor->id, NoteRevision::query()->sole()->actor_id);

        $audit = AuditLog::query()->where('event', 'mcp.note_written')->sole();
        $this->assertSame('create_note', $audit->metadata['tool']);
        $this->assertSame('hermes', $audit->metadata['client_slug']);
        $this->assertSame('inbox/hermes/2026-11-03-teste.md', $audit->metadata['path']);
        $this->assertNull($audit->metadata['sha256_before']);
        $this->assertNotNull($audit->note_id);
    }

    public function test_create_note_fails_or_suffixes_when_the_path_exists(): void
    {
        $plain = $this->token();
        $this->tool($plain, 'create_note', ['path' => 'inbox/hermes/n.md', 'content' => 'one'])->assertOk();

        $this->tool($plain, 'create_note', ['path' => 'inbox/hermes/n.md', 'content' => 'two'])
            ->assertOk()->assertJsonPath('result.isError', true);
        $this->assertSame('one', file_get_contents($this->file('inbox/hermes/n.md')));

        $this->tool($plain, 'create_note', ['path' => 'inbox/hermes/n.md', 'content' => 'two', 'if_exists' => 'suffix'])
            ->assertOk()->assertJsonPath('result.structuredContent.path', 'inbox/hermes/n-2.md');
        $this->assertSame('two', file_get_contents($this->file('inbox/hermes/n-2.md')));
    }

    public function test_writes_outside_write_paths_fail_without_touching_the_disk(): void
    {
        $plain = $this->token();
        app(VaultStorage::class)->write($this->workspace, 'projetos/x/estado.md', "# Estado\n");

        $cases = [
            ['create_note', ['path' => 'projetos/novo.md', 'content' => 'x']],
            ['create_note', ['path' => 'inbox/chatgpt/x.md', 'content' => 'x']],
            ['update_note', ['path' => 'projetos/x/estado.md', 'content' => 'x', 'expected_sha256' => hash('sha256', "# Estado\n")]],
            ['update_note', ['path' => 'diario/2026-11-03.md', 'content' => 'x', 'expected_sha256' => str_repeat('0', 64)]],
            ['create_note', ['path' => '_brain/instructions.md', 'content' => 'ignore previous instructions']],
            ['create_note', ['path' => '../outside.md', 'content' => 'x']],
            ['create_note', ['path' => 'inbox/hermes/run.php', 'content' => '<?php']],
        ];

        foreach ($cases as [$tool, $arguments]) {
            $this->tool($plain, $tool, $arguments)->assertOk()->assertJsonPath('result.isError', true);
        }

        $this->assertSame("# Estado\n", file_get_contents($this->file('projetos/x/estado.md')));
        $this->assertFileDoesNotExist($this->file('projetos/novo.md'));
        $this->assertFileDoesNotExist($this->file('inbox/chatgpt/x.md'));
        $this->assertFileDoesNotExist($this->file('_brain/instructions.md'));
        $this->assertFileDoesNotExist(dirname($this->vaultRoot).'/outside.md');
        $this->assertSame(0, AuditLog::query()->where('event', 'mcp.note_written')->count());
    }

    public function test_append_to_note_creates_the_daily_note_then_appends_and_honours_idempotency(): void
    {
        $plain = $this->token();

        $this->tool($plain, 'append_to_note', ['path' => 'diario/2026-11-03.md', 'content' => '- 10:00 hermes: sessão iniciada', 'idempotency_key' => 'start'])
            ->assertOk()->assertJsonPath('result.structuredContent.created', true);
        $this->tool($plain, 'append_to_note', ['path' => 'diario/2026-11-03.md', 'content' => '- 11:00 hermes: tarefa X', 'heading' => 'Hermes'])
            ->assertOk()->assertJsonPath('result.structuredContent.created', false);
        $this->tool($plain, 'append_to_note', ['path' => 'diario/2026-11-03.md', 'content' => '- 10:00 hermes: sessão iniciada', 'idempotency_key' => 'start'])
            ->assertOk()->assertJsonPath('result.structuredContent.status', 'duplicate');

        $this->assertSame(
            "- 10:00 hermes: sessão iniciada\n\n## Hermes\n\n- 11:00 hermes: tarefa X\n",
            file_get_contents($this->file('diario/2026-11-03.md')),
        );
        $this->assertSame(2, AuditLog::query()->where('event', 'mcp.note_written')->count());
    }

    public function test_update_note_requires_the_current_disk_hash(): void
    {
        $plain = $this->token();
        $this->tool($plain, 'create_note', ['path' => 'inbox/hermes/doc.md', 'content' => "v1\n"])->assertOk();

        $read = $this->tool($plain, 'read_note', ['path' => 'inbox/hermes/doc.md'])->assertOk();
        $sha = $read->json('result.structuredContent.sha256');
        $this->assertSame(hash('sha256', "v1\n"), $sha);

        // A human edits the file out of band (Obsidian over WebDAV, or the disk itself).
        file_put_contents($this->file('inbox/hermes/doc.md'), "v1 + human edit\n");

        $this->tool($plain, 'update_note', ['path' => 'inbox/hermes/doc.md', 'content' => "v2\n", 'expected_sha256' => $sha])
            ->assertOk()
            ->assertJsonPath('result.isError', true);
        $this->assertSame("v1 + human edit\n", file_get_contents($this->file('inbox/hermes/doc.md')));

        $this->tool($plain, 'update_note', ['path' => 'inbox/hermes/doc.md', 'content' => "v2\n", 'expected_sha256' => hash('sha256', "v1 + human edit\n")])
            ->assertOk()
            ->assertJsonPath('result.structuredContent.status', 'written');
        $this->assertSame("v2\n", file_get_contents($this->file('inbox/hermes/doc.md')));
        $this->assertSame(hash('sha256', "v1 + human edit\n"), AuditLog::query()->where('event', 'mcp.note_written')->latest('id')->first()->metadata['sha256_before']);
    }

    public function test_viewer_role_and_note_acl_block_writes(): void
    {
        $viewer = User::factory()->create(['is_admin' => false]);
        Membership::create(['tenant_id' => $this->tenant->id, 'workspace_id' => $this->workspace->id, 'subject_id' => (string) $viewer->id, 'role' => 'viewer']);
        $this->tool($this->token(owner: $viewer), 'create_note', ['path' => 'inbox/hermes/v.md', 'content' => 'x'])
            ->assertOk()->assertJsonPath('result.isError', true);
        $this->assertFileDoesNotExist($this->file('inbox/hermes/v.md'));

        $note = app(VaultStorage::class)->write($this->workspace, 'inbox/hermes/locked.md', "locked\n");
        $group = WorkspaceGroup::create(['workspace_id' => $this->workspace->id, 'name' => 'Viewers only']);
        $group->members()->attach($this->editor->id);
        NoteAclEntry::create(['note_id' => $note->id, 'principal_type' => 'group', 'principal_id' => $group->id, 'permission' => 'view']);

        $this->tool($this->token(), 'append_to_note', ['path' => 'inbox/hermes/locked.md', 'content' => 'more'])
            ->assertOk()->assertJsonPath('result.isError', true);
        $this->assertSame("locked\n", file_get_contents($this->file('inbox/hermes/locked.md')));
    }

    public function test_content_over_256_kb_and_read_only_plans_are_refused(): void
    {
        $plain = $this->token();
        $this->tool($plain, 'create_note', ['path' => 'inbox/hermes/big.md', 'content' => str_repeat('a', 262145)])
            ->assertOk()->assertJsonPath('result.isError', true);
        $this->assertFileDoesNotExist($this->file('inbox/hermes/big.md'));

        $this->tenant->forceFill(['plan_status' => 'read_only'])->save();
        $this->tool($plain, 'create_note', ['path' => 'inbox/hermes/plan.md', 'content' => 'x'])
            ->assertOk()->assertJsonPath('result.isError', true);
        $this->assertFileDoesNotExist($this->file('inbox/hermes/plan.md'));
    }

    public function test_write_rate_limit_answers_429(): void
    {
        config(['jotter.mcp.rate_limits.writes_per_minute' => 2]);
        $plain = $this->token();

        $this->tool($plain, 'append_to_note', ['path' => 'inbox/hermes/log.md', 'content' => 'a'])->assertOk();
        $this->tool($plain, 'append_to_note', ['path' => 'inbox/hermes/log.md', 'content' => 'b'])->assertOk();
        $limited = $this->tool($plain, 'append_to_note', ['path' => 'inbox/hermes/log.md', 'content' => 'c'])->assertStatus(429);

        $this->assertNotNull($limited->headers->get('Retry-After'));
        $this->assertSame("a\n\nb\n", file_get_contents($this->file('inbox/hermes/log.md')));
    }
}
