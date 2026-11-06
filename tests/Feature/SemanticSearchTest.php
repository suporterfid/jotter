<?php

namespace Tests\Feature;

use App\Domain\Semantic\Contracts\EmbeddingProvider;
use App\Domain\Semantic\EmbeddingFailed;
use App\Domain\Semantic\OpenAiCompatibleEmbeddings;
use App\Models\MachineToken;
use App\Models\Membership;
use App\Models\Note;
use App\Models\NoteAclEntry;
use App\Models\NoteEmbedding;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Issue #412: opt-in semantic search over a rebuildable embeddings projection,
 * built from the scheduler and filtered by NoteAccess and the credential.
 */
final class SemanticSearchTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Workspace $brain;
    private Workspace $other;
    private User $user;
    private FakeTopicEmbeddings $provider;

    protected function setUp(): void
    {
        parent::setUp();
        config(['jotter.auth_bypass' => false, 'jotter.auth_provider' => 'local', 'jotter.semantic.enabled' => true]);

        $this->provider = new FakeTopicEmbeddings;
        $this->app->instance(EmbeddingProvider::class, $this->provider);

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);
        $this->brain = Workspace::create(['tenant_id' => $this->tenant->id, 'slug' => 'brain', 'name' => 'Brain', 'vault_path' => storage_path('app/vaults/sem_b_'.uniqid())]);
        $this->other = Workspace::create(['tenant_id' => $this->tenant->id, 'slug' => 'brain-restrito', 'name' => 'Restrito', 'vault_path' => storage_path('app/vaults/sem_r_'.uniqid())]);
        $this->user = User::factory()->create(['is_admin' => false]);
        foreach ([$this->brain, $this->other] as $workspace) {
            Membership::create(['tenant_id' => $this->tenant->id, 'workspace_id' => $workspace->id, 'subject_id' => (string) $this->user->id, 'role' => 'editor']);
        }

        $this->note($this->brain, 'decisoes/cobranca.md', 'Cobrança recorrente', 'Decidimos cobrar a assinatura todo mês no cartão.');
        $this->note($this->brain, 'diario/ferias.md', 'Férias', 'Viagem para a praia em janeiro.');
        $this->note($this->other, 'clientes/segredo.md', 'Cliente X', 'Pagamento do cliente X atrasado.');
    }

    public function test_nothing_leaves_the_server_until_a_workspace_opts_in(): void
    {
        $this->assertSame(0, Artisan::call('semantic:index'));
        $this->assertSame(0, NoteEmbedding::query()->count());
        $this->assertSame(0, $this->provider->calls);

        $result = $this->semanticSearch('como cobramos a assinatura?');
        $this->assertSame('fulltext', $result['mode']);
        $this->assertStringContainsString('not enabled for this workspace', (string) $result['reason']);
        $this->assertSame(0, $this->provider->calls, 'a query on an opted-out workspace is never embedded either');
    }

    public function test_opted_in_workspace_is_indexed_and_searched_by_meaning(): void
    {
        $this->assertSame(0, Artisan::call('semantic:index', ['--workspace' => 'brain', '--enable' => true]));
        $this->assertTrue($this->brain->fresh()->semantic_search);
        $this->assertSame(2, NoteEmbedding::query()->where('workspace_id', $this->brain->id)->distinct()->count('note_id'));
        $this->assertSame(0, NoteEmbedding::query()->where('workspace_id', $this->other->id)->count(), 'the other workspace stays out');

        // "pagar mensalidade" shares no word with the note, only its meaning.
        $result = $this->semanticSearch('quando pagar a mensalidade?');
        $this->assertSame('semantic', $result['mode']);
        $this->assertSame('decisoes/cobranca.md', $result['results'][0]['path']);
        $this->assertGreaterThan($result['results'][1]['score'], $result['results'][0]['score']);
        $this->assertStringContainsString('assinatura', $result['results'][0]['snippet']);
    }

    public function test_only_changed_notes_are_embedded_again(): void
    {
        Artisan::call('semantic:index', ['--workspace' => 'brain', '--enable' => true]);
        $calls = $this->provider->calls;

        Artisan::call('semantic:index');
        $this->assertSame($calls, $this->provider->calls, 'nothing changed, nothing embedded');

        Note::query()->where('path', 'diario/ferias.md')->update(['content_hash' => str_repeat('e', 64), 'search_content' => 'Viagem cancelada, vamos pagar a assinatura.']);
        Artisan::call('semantic:index', ['--json' => true]);
        $this->assertSame(1, json_decode(Artisan::output(), true)['indexed']);
        $this->assertSame(str_repeat('e', 64), NoteEmbedding::query()->whereHas('note', static fn ($q) => $q->where('path', 'diario/ferias.md'))->value('content_hash'));
    }

    public function test_results_respect_note_acls_and_the_credential_workspaces(): void
    {
        Artisan::call('semantic:index', ['--workspace' => 'brain', '--enable' => true]);
        $hidden = Note::query()->where('path', 'decisoes/cobranca.md')->sole();
        NoteAclEntry::create(['note_id' => $hidden->id, 'principal_type' => 'user', 'principal_id' => User::factory()->create()->id, 'permission' => 'view']);

        $paths = array_column($this->semanticSearch('quando pagar a mensalidade?')['results'], 'path');
        $this->assertNotContains('decisoes/cobranca.md', $paths);

        // A token limited to brain cannot search brain-restrito.
        Artisan::call('semantic:index', ['--workspace' => 'brain-restrito', '--enable' => true]);
        $this->flushHeaders();
        $this->withToken($this->token([$this->brain->id]))->postJson('/api/mcp', [
            'jsonrpc' => '2.0', 'id' => 9, 'method' => 'tools/call',
            'params' => ['name' => 'semantic_search', 'arguments' => ['workspace_id' => $this->other->id, 'query' => 'pagamento']],
        ])->assertStatus(403);
    }

    public function test_disable_and_rebuild_treat_the_index_as_a_projection(): void
    {
        Artisan::call('semantic:index', ['--workspace' => 'brain', '--enable' => true]);
        $before = NoteEmbedding::query()->orderBy('note_id')->orderBy('chunk_index')->pluck('chunk_text')->all();

        Artisan::call('semantic:index', ['--rebuild' => true]);
        $this->assertSame($before, NoteEmbedding::query()->orderBy('note_id')->orderBy('chunk_index')->pluck('chunk_text')->all());

        Artisan::call('semantic:index', ['--workspace' => 'brain', '--disable' => true]);
        $this->assertSame(0, NoteEmbedding::query()->count());
        $this->assertFalse($this->brain->fresh()->semantic_search);

        // Turning the feature off globally deletes whatever is left.
        Artisan::call('semantic:index', ['--workspace' => 'brain', '--enable' => true]);
        config(['jotter.semantic.enabled' => false]);
        Artisan::call('semantic:index');
        $this->assertSame(0, NoteEmbedding::query()->count());
        $this->assertSame('fulltext', $this->semanticSearch('assinatura')['mode']);
    }

    public function test_a_failing_provider_stops_indexing_and_queries_fall_back_to_full_text(): void
    {
        Artisan::call('semantic:index', ['--workspace' => 'brain', '--enable' => true]);
        $this->provider->fail = true;

        $result = $this->semanticSearch('assinatura');
        $this->assertSame('fulltext', $result['mode']);
        $this->assertStringContainsString('provider down', (string) $result['reason']);

        Note::query()->where('path', 'diario/ferias.md')->update(['content_hash' => str_repeat('f', 64)]);
        $this->assertSame(1, Artisan::call('semantic:index', ['--json' => true]));
        $stats = json_decode(Artisan::output(), true);
        $this->assertSame(1, $stats['failed']);
        $this->assertSame('provider down', $stats['error']);
    }

    public function test_the_openai_compatible_provider_sends_model_dimensions_and_key(): void
    {
        config(['jotter.semantic.base_url' => 'https://embed.example.com/v1/', 'jotter.semantic.api_key' => 'sk-test', 'jotter.semantic.model' => 'text-embedding-3-small', 'jotter.semantic.dimensions' => 4]);
        Http::fake(['https://embed.example.com/v1/embeddings' => Http::response(['data' => [
            ['index' => 1, 'embedding' => [0, 1, 0, 0]],
            ['index' => 0, 'embedding' => [1, 0, 0, 0]],
        ]])]);

        $vectors = (new OpenAiCompatibleEmbeddings)->embed(['a', 'b'], 5);

        $this->assertSame([[1.0, 0.0, 0.0, 0.0], [0.0, 1.0, 0.0, 0.0]], $vectors, 'reordered by index');
        $this->assertSame('text-embedding-3-small@4', (new OpenAiCompatibleEmbeddings)->model());
        Http::assertSent(static fn (HttpRequest $request): bool => $request->hasHeader('Authorization', 'Bearer sk-test')
            && $request['model'] === 'text-embedding-3-small' && $request['dimensions'] === 4 && $request['input'] === ['a', 'b']);
    }

    /**
     * @return array<string, mixed>
     */
    private function semanticSearch(string $query): array
    {
        $this->flushHeaders();
        $response = $this->withToken($this->token())->postJson('/api/mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'semantic_search', 'arguments' => ['workspace_id' => $this->brain->id, 'query' => $query]],
        ])->assertOk();

        return (array) $response->json('result.structuredContent');
    }

    /**
     * @param list<int>|null $workspaceIds
     */
    private function token(?array $workspaceIds = null): string
    {
        $plain = 'jt_mkt_sem_'.bin2hex(random_bytes(8));
        MachineToken::create([
            'tenant_id' => $this->tenant->id,
            'subject_id' => (string) $this->user->id,
            'name' => 'Agent',
            'scopes' => ['mcp:read'],
            'workspace_ids' => $workspaceIds,
            'token_hash' => MachineToken::hashToken($plain),
        ]);

        return $plain;
    }

    private function note(Workspace $workspace, string $path, string $title, string $text): void
    {
        Note::create([
            'workspace_id' => $workspace->id,
            'path' => $path,
            'title' => $title,
            'search_content' => $text,
            'content_hash' => hash('sha256', $title.$text),
        ]);
    }
}

/**
 * Deterministic stand-in for an embedding model: each word votes for a topic,
 * so "pagar mensalidade" lands next to "cobrar assinatura" without sharing words.
 */
final class FakeTopicEmbeddings implements EmbeddingProvider
{
    public int $calls = 0;

    public bool $fail = false;

    private const TOPICS = [
        'cobrança' => 0, 'cobrar' => 0, 'cobramos' => 0, 'assinatura' => 0, 'pagar' => 0, 'pagamento' => 0, 'mensalidade' => 0, 'cartão' => 0, 'recorrente' => 0, 'mês' => 0,
        'férias' => 1, 'viagem' => 1, 'praia' => 1, 'janeiro' => 1,
        'cliente' => 2, 'atrasado' => 2,
    ];

    public function embed(array $inputs, int $timeoutSeconds): array
    {
        $this->calls++;
        if ($this->fail) {
            throw new EmbeddingFailed('provider down');
        }

        return array_map(static function (string $input): array {
            $vector = [0.01, 0.01, 0.01, 0.01];
            foreach (preg_split('/[^\p{L}]+/u', mb_strtolower($input)) ?: [] as $word) {
                if (isset(self::TOPICS[$word])) {
                    $vector[self::TOPICS[$word]] += 1.0;
                }
            }

            return $vector;
        }, $inputs);
    }

    public function model(): string
    {
        return 'fake-topics@4';
    }
}
