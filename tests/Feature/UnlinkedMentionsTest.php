<?php

namespace Tests\Feature;

use App\Domain\Vault\VaultReindexer;
use App\Domain\Vault\VaultStorage;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class UnlinkedMentionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_finds_notes_that_mention_the_title_without_a_wikilink(): void
    {
        $workspace = $this->makeWorkspace();
        $storage = new VaultStorage;

        $target = $storage->write($workspace, 'project-alpha.md', "# Project Alpha\n\nThe flagship effort.");
        $mentioning = $storage->write($workspace, 'meeting-notes.md', "We discussed Project Alpha today, no link yet.");
        $storage->write($workspace, 'unrelated.md', "Nothing to do with it.");

        $admin = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($admin)
            ->getJson("/api/workspaces/{$workspace->id}/notes/{$target->id}/unlinked-mentions");

        $response->assertOk()->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $mentioning->id);
        $response->assertJsonPath('data.0.path', 'meeting-notes.md');
        $response->assertJsonPath('data.0.matched_phrase', 'Project Alpha');
    }

    public function test_excludes_notes_that_already_have_a_resolved_wikilink(): void
    {
        $workspace = $this->makeWorkspace();
        $storage = new VaultStorage;

        $target = $storage->write($workspace, 'project-alpha.md', "# Project Alpha\n\nThe flagship effort.");
        $storage->write($workspace, 'linked.md', "See [[project-alpha]] for the plan on Project Alpha.");

        $admin = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($admin)
            ->getJson("/api/workspaces/{$workspace->id}/notes/{$target->id}/unlinked-mentions");

        $response->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_matches_via_frontmatter_alias(): void
    {
        $workspace = $this->makeWorkspace();
        $storage = new VaultStorage;

        $target = $storage->write($workspace, 'research.md', "---\naliases: [Deep Research]\n---\n# Research\n");
        $mentioning = $storage->write($workspace, 'notes.md', "Reading up on Deep Research this week.");

        $admin = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($admin)
            ->getJson("/api/workspaces/{$workspace->id}/notes/{$target->id}/unlinked-mentions");

        $response->assertOk()->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $mentioning->id);
    }

    public function test_converting_the_mention_into_a_wikilink_removes_it_from_results(): void
    {
        $workspace = $this->makeWorkspace();
        $storage = new VaultStorage;

        $target = $storage->write($workspace, 'project-alpha.md', "# Project Alpha\n\nThe flagship effort.");
        $storage->write($workspace, 'meeting-notes.md', "We discussed Project Alpha today.");

        /** @var VaultReindexer $reindexer */
        $reindexer = $this->app->make(VaultReindexer::class);
        $reindexer->reindex($workspace);

        $admin = User::factory()->create(['is_admin' => true]);
        $before = $this->actingAs($admin)
            ->getJson("/api/workspaces/{$workspace->id}/notes/{$target->id}/unlinked-mentions");
        $before->assertJsonCount(1, 'data');

        $storage->write($workspace, 'meeting-notes.md', "We discussed [[Project Alpha]] today.");
        $reindexer->reindex($workspace);

        $after = $this->actingAs($admin)
            ->getJson("/api/workspaces/{$workspace->id}/notes/{$target->id}/unlinked-mentions");
        $after->assertJsonCount(0, 'data');
    }

    private function makeWorkspace(): Workspace
    {
        $tenant = Tenant::query()->create([
            'slug' => 'unlinked-tenant-'.uniqid(),
            'name' => 'Unlinked Mentions Tenant',
        ]);

        $vaultPath = storage_path('app/vaults/unlinked_'.uniqid());
        mkdir($vaultPath, 0755, true);

        return Workspace::query()->create([
            'tenant_id' => $tenant->id,
            'slug' => 'unlinked-ws-'.uniqid(),
            'name' => 'Unlinked Mentions Workspace',
            'vault_path' => $vaultPath,
        ]);
    }
}
