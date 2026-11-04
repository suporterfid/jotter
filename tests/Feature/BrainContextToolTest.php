<?php

namespace Tests\Feature;

use App\Domain\Auth\MachineTokenIssuer;
use App\Domain\Auth\MachineTokenOptions;
use App\Domain\Brain\BrainPack;
use App\Domain\Mcp\BrainTools;
use App\Domain\Vault\VaultStorage;
use App\Models\Membership;
use App\Models\NoteAclEntry;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class BrainContextToolTest extends TestCase
{
    use RefreshDatabase;

    private string $vaultRoot;
    private Tenant $tenant;
    private Workspace $workspace;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['jotter.auth_bypass' => false, 'jotter.auth_provider' => 'local']);

        $this->vaultRoot = sys_get_temp_dir().'/jotter-brain-ctx-'.uniqid('', true);
        File::ensureDirectoryExists($this->vaultRoot);
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);
        $this->workspace = Workspace::create(['tenant_id' => $this->tenant->id, 'slug' => 'brain', 'name' => 'Brain', 'vault_path' => $this->vaultRoot]);
        $this->user = User::factory()->create(['is_admin' => false, 'name' => 'Joe']);
        Membership::create(['tenant_id' => $this->tenant->id, 'workspace_id' => $this->workspace->id, 'subject_id' => (string) $this->user->id, 'role' => 'editor']);
        (new BrainPack)->install($this->workspace, 'pt-BR', (string) $this->user->id);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->vaultRoot);
        parent::tearDown();
    }

    private function token(bool $write = false): string
    {
        $scopes = $write ? ['mcp:read', 'mcp:write'] : ['mcp:read'];
        $options = MachineTokenOptions::fromInput($this->user, $this->tenant, $scopes, null, $write ? ['inbox/{client}/**:create,append,update'] : [], 'hermes');

        return app(MachineTokenIssuer::class)->issue($this->user, $this->tenant, 'brain', $options)['plain'];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function tool(string $plain, string $name, array $arguments = []): TestResponse
    {
        return $this->withToken($plain)->postJson('/api/mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $arguments],
        ]);
    }

    public function test_one_call_returns_router_rules_projects_and_recent_changes(): void
    {
        app(VaultStorage::class)->write($this->workspace, 'projetos/gestrato/estado.md', "---\nstatus: ativo\nproximo_passo: Publicar a v2\natualizado: 2026-11-03\ndono: Joe\n---\n# Gestrato\n", (string) $this->user->id);
        $writer = $this->token(write: true);
        $this->tool($writer, 'create_note', ['path' => 'inbox/hermes/achado.md', 'content' => "# Achado\n"])->assertOk();

        $response = $this->tool($this->token(), 'get_brain_context')->assertOk();
        $context = $response->json('result.structuredContent');

        $this->assertStringContainsString('Brain — por onde começar', $context['readme']);
        $this->assertStringContainsString('get_brain_context', $context['instructions']);
        $projects = collect($context['projects'])->keyBy('project');
        $this->assertSame('Publicar a v2', $projects['gestrato']['proximo_passo']);
        $this->assertSame('ativo', $projects['gestrato']['status']);
        $this->assertSame('inbox/hermes/achado.md', $context['recent_changes'][0]['path']);
        $this->assertSame('hermes', $context['recent_changes'][0]['client']);
        $this->assertSame('Joe', $context['recent_changes'][0]['actor']);
        $this->assertLessThanOrEqual(BrainTools::CONTEXT_MAX_BYTES, strlen((string) $response->json('result.content.0.text')));
        $this->assertFalse($context['truncated']);
    }

    public function test_context_is_cut_to_24_kilobytes(): void
    {
        file_put_contents($this->vaultRoot.'/_brain/README.md', str_repeat("Linha longa do roteador com acentuação.\n", 1500));
        app(VaultStorage::class)->write($this->workspace, '_brain/README.md', (string) file_get_contents($this->vaultRoot.'/_brain/README.md'));

        $response = $this->tool($this->token(), 'get_brain_context')->assertOk();

        $this->assertLessThanOrEqual(BrainTools::CONTEXT_MAX_BYTES, strlen((string) $response->json('result.content.0.text')));
        $this->assertTrue($response->json('result.structuredContent.truncated'));
        $this->assertTrue(mb_check_encoding((string) $response->json('result.structuredContent.readme'), 'UTF-8'));
    }

    public function test_context_respects_note_acls(): void
    {
        $other = User::factory()->create();
        $group = WorkspaceGroup::create(['workspace_id' => $this->workspace->id, 'name' => 'Others']);
        $group->members()->attach($other->id);
        $secret = app(VaultStorage::class)->write($this->workspace, 'projetos/sigiloso/estado.md', "---\nstatus: ativo\n---\n# Sigiloso\n");
        NoteAclEntry::create(['note_id' => $secret->id, 'principal_type' => 'group', 'principal_id' => $group->id, 'permission' => 'view']);

        $projects = $this->tool($this->token(), 'get_brain_context')->assertOk()->json('result.structuredContent.projects');

        $this->assertNotContains('sigiloso', array_column($projects, 'project'));
        $this->assertContains('exemplo', array_column($projects, 'project'));
    }

    public function test_list_recent_changes_and_outgoing_links(): void
    {
        app(VaultStorage::class)->write($this->workspace, 'projetos/a/estado.md', "# A\n\nVer [[decisoes/README]] e [[Inexistente]].\n");

        $changes = $this->tool($this->token(), 'list_recent_changes', ['limit' => 3])->assertOk()->json('result.structuredContent.changes');
        $this->assertCount(3, $changes);
        $this->assertSame('projetos/a/estado.md', $changes[0]['path']);
        $this->tool($this->token(), 'list_recent_changes', ['since' => 'not a date'])->assertOk()->assertJsonPath('result.isError', true);
        $this->assertSame([], $this->tool($this->token(), 'list_recent_changes', ['since' => '2999-01-01T00:00:00Z'])->json('result.structuredContent.changes'));

        $links = $this->tool($this->token(), 'get_outgoing_links', ['path' => 'projetos/a/estado.md'])->assertOk()->json('result.structuredContent.links');
        $this->assertSame([true, false], array_column($links, 'resolved'));
        $this->assertSame('decisoes/README.md', $links[0]['path']);
    }
}
