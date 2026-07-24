<?php

namespace Tests\Feature;

use App\Domain\Vault\VaultStorage;
use App\Models\Tenant;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WorkspaceSearchTest extends TestCase
{
    use DatabaseMigrations;

    private string $vaultRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vaultRoot = sys_get_temp_dir().'/jotter-search-'.uniqid('', true);
        mkdir($this->vaultRoot, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->vaultRoot);

        parent::tearDown();
    }

    public function test_notes_have_a_fulltext_index_for_the_rebuildable_title_and_search_projection(): void
    {
        $this->assertTrue(
            Schema::hasIndex('notes', 'notes_title_search_content_fulltext', 'fulltext'),
        );
    }

    public function test_search_returns_ranked_workspace_scoped_matches_with_snippets(): void
    {
        $workspace = $this->makeWorkspace('primary');
        $otherWorkspace = $this->makeWorkspace('other');
        $storage = new VaultStorage;

        $titleMatch = $storage->write($workspace, 'rare-title.md', "# Zephyrneedle\nA short body.\n");
        $storage->write($workspace, 'body-match.md', "# Body match\nZephyrneedle appears only in this projected body.\n");
        $storage->write($otherWorkspace, 'private.md', "# Zephyrneedle\nThis must not cross workspace boundaries.\n");
        foreach (range(1, 4) as $number) {
            $storage->write($workspace, "filler-{$number}.md", "# Filler {$number}\nUnrelated notebook material.\n");
        }

        $response = $this->getJson("/api/workspaces/{$workspace->id}/search?q=Zephyrneedle");

        $response
            ->assertOk()
            ->assertJsonPath('data.0.id', $titleMatch->id)
            ->assertJsonPath('data.0.path', 'rare-title.md')
            ->assertJsonPath('data.0.title', 'Zephyrneedle')
            ->assertJsonMissing(['path' => 'private.md']);

        $results = $response->json('data');
        $this->assertCount(2, $results);
        $this->assertIsFloat($results[0]['relevance']);
        $this->assertGreaterThanOrEqual($results[1]['relevance'], $results[0]['relevance']);
        $this->assertStringContainsString('Zephyrneedle', $results[0]['snippet']);
        $this->assertLessThanOrEqual(240, mb_strlen($results[0]['snippet']));
        $this->assertArrayNotHasKey('search_content', $results[0]);
    }

    public function test_search_requires_a_non_blank_bounded_query(): void
    {
        $workspace = $this->makeWorkspace('validation');

        $this->getJson("/api/workspaces/{$workspace->id}/search")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('q');

        $this->getJson("/api/workspaces/{$workspace->id}/search?q=%20%20%20")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('q');

        $this->getJson('/api/workspaces/'.$workspace->id.'/search?q='.str_repeat('a', 201))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('q');
    }

    public function test_search_snippets_include_a_matched_title_or_query_term(): void
    {
        $workspace = $this->makeWorkspace('snippets');
        $storage = new VaultStorage;

        $storage->write($workspace, 'title-only.md', "---\ntitle: Auroracrown\n---\nBody without the title token.\n");

        $titleOnly = $this->getJson("/api/workspaces/{$workspace->id}/search?q=Auroracrown");
        $titleOnly
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Auroracrown');
        $this->assertStringContainsString('Auroracrown', $titleOnly->json('data.0.snippet'));

        $workspace = $this->makeWorkspace('multi-term');
        $storage->write(
            $workspace,
            'multi-term.md',
            "---\ntitle: Nebulaquartz\n---\n".str_repeat('filler ', 80).'Cometfrost appears after the excerpt boundary.',
        );

        $multiTerm = $this->getJson("/api/workspaces/{$workspace->id}/search?q=Nebulaquartz%20Cometfrost");
        $multiTerm->assertOk()->assertJsonPath('data.0.title', 'Nebulaquartz');

        $snippet = $multiTerm->json('data.0.snippet');
        $this->assertTrue(
            str_contains($snippet, 'Nebulaquartz') || str_contains($snippet, 'Cometfrost'),
            "Expected a snippet around one of the matched query terms, got [{$snippet}].",
        );
    }

    private function makeWorkspace(string $suffix): Workspace
    {
        $tenant = Tenant::query()->create([
            'slug' => "search-tenant-{$suffix}-".uniqid(),
            'name' => 'Search Tenant',
        ]);

        return Workspace::query()->create([
            'tenant_id' => $tenant->id,
            'slug' => "search-ws-{$suffix}-".uniqid(),
            'name' => 'Search Workspace',
            'vault_path' => $this->vaultRoot.'/'.$suffix,
        ]);
    }

    private function deleteTree(string $path): void
    {
        if (! is_dir($path) && ! is_file($path)) {
            return;
        }

        if (is_file($path)) {
            @unlink($path);

            return;
        }

        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $this->deleteTree($path.DIRECTORY_SEPARATOR.$item);
        }

        @rmdir($path);
    }
}
