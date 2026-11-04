<?php

namespace Tests\Feature;

use App\Domain\Vault\VaultStorage;
use App\Models\Membership;
use App\Models\Note;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * REST saves with `base_sha256` (brain plan PR 3): the SPA sends the hash it
 * loaded; a save over a newer file (an agent, Obsidian over WebDAV, a direct
 * disk edit not yet reindexed) answers 409 and writes nothing.
 */
final class NoteOptimisticConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private string $vaultRoot;
    private Workspace $workspace;
    private User $editor;
    private Note $note;

    protected function setUp(): void
    {
        parent::setUp();
        config(['jotter.auth_bypass' => false, 'jotter.auth_provider' => 'local']);

        $this->vaultRoot = sys_get_temp_dir().'/jotter-occ-'.uniqid('', true);
        File::ensureDirectoryExists($this->vaultRoot);
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);
        $this->workspace = Workspace::create(['tenant_id' => $tenant->id, 'slug' => 'brain', 'name' => 'Brain', 'vault_path' => $this->vaultRoot]);
        $this->editor = User::factory()->create(['is_admin' => false]);
        Membership::create(['tenant_id' => $tenant->id, 'workspace_id' => $this->workspace->id, 'subject_id' => (string) $this->editor->id, 'role' => 'editor']);
        $this->note = app(VaultStorage::class)->write($this->workspace, 'note.md', "v1\n");
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->vaultRoot);
        parent::tearDown();
    }

    private function url(): string
    {
        return "/api/workspaces/{$this->workspace->id}/notes/{$this->note->id}";
    }

    public function test_show_and_update_expose_the_content_hash(): void
    {
        $this->actingAs($this->editor)->getJson($this->url())->assertOk()->assertJsonPath('data.content_hash', hash('sha256', "v1\n"));

        $this->actingAs($this->editor)->putJson($this->url(), ['content' => "v2\n", 'base_sha256' => hash('sha256', "v1\n")])
            ->assertOk()
            ->assertJsonPath('data.content_hash', hash('sha256', "v2\n"));
        $this->assertSame("v2\n", file_get_contents($this->vaultRoot.'/note.md'));
    }

    public function test_stale_base_answers_409_and_writes_nothing(): void
    {
        // Out-of-band edit: the index still holds the v1 hash.
        file_put_contents($this->vaultRoot.'/note.md', "v1 edited in Obsidian\n");
        $this->assertSame(hash('sha256', "v1\n"), $this->note->fresh()->content_hash);

        $this->actingAs($this->editor)->putJson($this->url(), ['content' => "mine\n", 'base_sha256' => hash('sha256', "v1\n")])
            ->assertStatus(409)
            ->assertJsonPath('current_sha256', hash('sha256', "v1 edited in Obsidian\n"));
        $this->assertSame("v1 edited in Obsidian\n", file_get_contents($this->vaultRoot.'/note.md'));
    }

    public function test_save_without_base_still_overwrites_for_backwards_compatibility(): void
    {
        file_put_contents($this->vaultRoot.'/note.md', "other\n");

        $this->actingAs($this->editor)->putJson($this->url(), ['content' => "mine\n"])->assertOk();
        $this->assertSame("mine\n", file_get_contents($this->vaultRoot.'/note.md'));
    }

    public function test_malformed_base_is_a_validation_error(): void
    {
        $this->actingAs($this->editor)->putJson($this->url(), ['content' => "x\n", 'base_sha256' => 'not-a-hash'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['base_sha256']);
    }
}
