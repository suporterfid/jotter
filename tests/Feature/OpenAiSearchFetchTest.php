<?php

namespace Tests\Feature;

use App\Domain\Auth\MachineTokenIssuer;
use App\Domain\Auth\MachineTokenOptions;
use App\Domain\Vault\VaultStorage;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * `search` and `fetch` in the OpenAI connector shape, so ChatGPT (including
 * Deep Research, which only calls these two) can use the brain.
 */
final class OpenAiSearchFetchTest extends TestCase
{
    // InnoDB FULLTEXT does not see uncommitted rows, so search tests commit (as WorkspaceSearchTest does).
    use DatabaseMigrations;

    /** @var list<string> */
    private array $roots = [];
    private Tenant $tenant;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['jotter.auth_bypass' => false, 'jotter.auth_provider' => 'local', 'app.url' => 'https://brain.example.test']);
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);
        $this->user = User::factory()->create(['is_admin' => false]);
    }

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            File::deleteDirectory($root);
        }
        parent::tearDown();
    }

    private function workspace(string $slug, bool $member = true): Workspace
    {
        $root = sys_get_temp_dir().'/jotter-oai-'.$slug.'-'.uniqid('', true);
        File::ensureDirectoryExists($root);
        $this->roots[] = $root;
        $workspace = Workspace::create(['tenant_id' => $this->tenant->id, 'slug' => $slug, 'name' => ucfirst($slug), 'vault_path' => $root]);
        if ($member) {
            Membership::create(['tenant_id' => $this->tenant->id, 'workspace_id' => $workspace->id, 'subject_id' => (string) $this->user->id, 'role' => 'viewer']);
        }

        return $workspace;
    }

    /**
     * @param list<string>|null $workspaces
     */
    private function token(?array $workspaces = null): string
    {
        $options = MachineTokenOptions::fromInput($this->user, $this->tenant, ['mcp:read'], $workspaces);

        return app(MachineTokenIssuer::class)->issue($this->user, $this->tenant, 'oai', $options)['plain'];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function tool(string $plain, string $name, array $arguments): TestResponse
    {
        return $this->withToken($plain)->postJson('/api/mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $arguments],
        ]);
    }

    public function test_search_returns_openai_results_across_reachable_workspaces_only(): void
    {
        $brain = $this->workspace('brain');
        $other = $this->workspace('restrito');
        $notMine = $this->workspace('alheio', member: false);
        app(VaultStorage::class)->write($brain, 'projetos/gestrato.md', "# Gestrato\n\nplataforma de orquestração\n");
        app(VaultStorage::class)->write($other, 'cliente.md', "# Cliente\n\norquestração sigilosa\n");
        app(VaultStorage::class)->write($notMine, 'alheio.md', "# Alheio\n\norquestração alheia\n");

        $response = $this->tool($this->token(['brain']), 'search', ['query' => 'orquestração'])->assertOk();
        $results = $response->json('result.structuredContent.results');

        $this->assertSame([$brain->id.':projetos/gestrato.md'], array_column($results, 'id'));
        $this->assertSame('Gestrato', $results[0]['title']);
        $this->assertStringStartsWith('https://brain.example.test/?workspace='.$brain->id.'&note=', $results[0]['url']);
        $this->assertSame(['results' => $results], json_decode((string) $response->json('result.content.0.text'), true));

        $this->assertCount(2, $this->tool($this->token(), 'search', ['query' => 'orquestração'])->json('result.structuredContent.results'));
        $this->tool($this->token(), 'search', ['query' => '  '])->assertOk()->assertJsonPath('result.isError', true);
    }

    public function test_fetch_returns_the_note_text_or_an_error_for_hidden_ids(): void
    {
        $brain = $this->workspace('brain');
        $notMine = $this->workspace('alheio', member: false);
        app(VaultStorage::class)->write($brain, 'projetos/gestrato.md', "# Gestrato\n\ntexto completo\n");
        app(VaultStorage::class)->write($notMine, 'alheio.md', "# Alheio\n");

        $document = $this->tool($this->token(), 'fetch', ['id' => $brain->id.':projetos/gestrato.md'])->assertOk()->json('result.structuredContent');

        $this->assertSame($brain->id.':projetos/gestrato.md', $document['id']);
        $this->assertSame("# Gestrato\n\ntexto completo\n", $document['text']);
        $this->assertSame('brain', $document['metadata']['workspace']);
        $this->assertSame(hash('sha256', "# Gestrato\n\ntexto completo\n"), $document['metadata']['sha256']);

        $this->tool($this->token(), 'fetch', ['id' => $notMine->id.':alheio.md'])->assertOk()->assertJsonPath('result.isError', true);
        $this->tool($this->token(), 'fetch', ['id' => 'garbage'])->assertOk()->assertJsonPath('result.isError', true);
    }
}
