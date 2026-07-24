<?php

namespace Tests\Feature;

use App\Domain\Vault\VaultPathGuard;
use App\Http\Middleware\WorkspaceAuthorizationPlaceholder;
use App\Models\Note;
use App\Models\Tenant;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceNotesApiTest extends TestCase
{
    use RefreshDatabase;

    private string $vaultRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vaultRoot = sys_get_temp_dir().'/jotter-notes-api-'.uniqid('', true);
        mkdir($this->vaultRoot, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->vaultRoot);

        parent::tearDown();
    }

    public function test_create_and_list_keep_markdown_on_disk_and_return_only_note_metadata(): void
    {
        $this->withoutMiddleware(WorkspaceAuthorizationPlaceholder::class);
        $workspace = $this->makeWorkspace('primary');
        $markdown = "---\ntitle: API Note\ntags: [api]\n---\nCanonical body.\n";

        $created = $this->postJson("/api/workspaces/{$workspace->id}/notes", [
            'path' => 'inbox/api-note.md',
            'content' => $markdown,
        ]);

        $created
            ->assertCreated()
            ->assertJsonPath('data.path', 'inbox/api-note.md')
            ->assertJsonPath('data.title', 'API Note')
            ->assertJsonMissing(['search_content' => 'Canonical body.']);

        $note = Note::query()->where('workspace_id', $workspace->id)->sole();
        $this->assertFileExists($this->vaultRoot.'/primary/inbox/api-note.md');
        $this->assertSame($markdown, file_get_contents($this->vaultRoot.'/primary/inbox/api-note.md'));
        $this->assertNull(Note::query()->find($note->id)->getAttribute('content'));

        $this->getJson("/api/workspaces/{$workspace->id}/notes")
            ->assertOk()
            ->assertJsonPath('data.0.id', $note->id)
            ->assertJsonPath('data.0.path', 'inbox/api-note.md')
            ->assertJsonMissing(['search_content' => 'Canonical body.']);
    }

    public function test_show_reads_the_current_markdown_file_instead_of_a_database_body(): void
    {
        $this->withoutMiddleware(WorkspaceAuthorizationPlaceholder::class);
        $workspace = $this->makeWorkspace('read');
        $note = $this->createNote($workspace, 'read.md', "# Before\n");

        file_put_contents($this->vaultRoot.'/read/read.md', "# Edited outside Jotter\n");

        $this->getJson("/api/workspaces/{$workspace->id}/notes/{$note->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $note->id)
            ->assertJsonPath('data.content', "# Edited outside Jotter\n")
            ->assertJsonMissing(['search_content' => '# Before']);
    }

    public function test_show_returns_not_found_without_a_false_traversal_audit_when_the_file_is_missing(): void
    {
        $this->withoutMiddleware(WorkspaceAuthorizationPlaceholder::class);
        $workspace = $this->makeWorkspace('missing');
        $note = $this->createNote($workspace, 'missing.md', "# Missing\n");

        unlink($this->vaultRoot.'/missing/missing.md');

        $this->getJson("/api/workspaces/{$workspace->id}/notes/{$note->id}")
            ->assertNotFound();

        $this->assertDatabaseCount('audit_log', 0);
    }

    public function test_delete_returns_not_found_without_a_false_traversal_audit_when_the_file_is_missing(): void
    {
        $this->withoutMiddleware(WorkspaceAuthorizationPlaceholder::class);
        $workspace = $this->makeWorkspace('missing-delete');
        $note = $this->createNote($workspace, 'missing.md', "# Missing\n");

        unlink($this->vaultRoot.'/missing-delete/missing.md');

        $this->deleteJson("/api/workspaces/{$workspace->id}/notes/{$note->id}")
            ->assertNotFound();

        $this->assertDatabaseCount('audit_log', 0);
    }

    public function test_update_and_delete_change_the_vault_file_and_rebuildable_index(): void
    {
        $this->withoutMiddleware(WorkspaceAuthorizationPlaceholder::class);
        $workspace = $this->makeWorkspace('mutate');
        $note = $this->createNote($workspace, 'nested/note.md', "# Before\n");

        $this->putJson("/api/workspaces/{$workspace->id}/notes/{$note->id}", [
            'content' => "# After\nUpdated body.\n",
        ])
            ->assertOk()
            ->assertJsonPath('data.title', 'After');

        $this->assertSame("# After\nUpdated body.\n", file_get_contents($this->vaultRoot.'/mutate/nested/note.md'));
        $this->assertSame('After', $note->fresh()->title);

        $this->deleteJson("/api/workspaces/{$workspace->id}/notes/{$note->id}")
            ->assertNoContent();

        $this->assertFileDoesNotExist($this->vaultRoot.'/mutate/nested/note.md');
        $this->assertDatabaseMissing('notes', ['id' => $note->id]);
    }

    public function test_create_and_update_allow_an_explicitly_empty_markdown_document(): void
    {
        $this->withoutMiddleware(WorkspaceAuthorizationPlaceholder::class);
        $workspace = $this->makeWorkspace('empty');

        $created = $this->postJson("/api/workspaces/{$workspace->id}/notes", [
            'path' => 'empty.md',
            'content' => '',
        ])->assertCreated();

        $noteId = $created->json('data.id');
        $this->assertSame('', file_get_contents($this->vaultRoot.'/empty/empty.md'));

        $this->putJson("/api/workspaces/{$workspace->id}/notes/{$noteId}", ['content' => ''])
            ->assertOk();
        $this->assertSame('', file_get_contents($this->vaultRoot.'/empty/empty.md'));
    }

    public function test_note_routes_cannot_cross_workspace_boundaries_or_accept_unsafe_paths(): void
    {
        $this->withoutMiddleware(WorkspaceAuthorizationPlaceholder::class);
        $workspace = $this->makeWorkspace('primary');
        $otherWorkspace = $this->makeWorkspace('other');
        $otherNote = $this->createNote($otherWorkspace, 'other.md', "# Other\n");

        $this->getJson("/api/workspaces/{$workspace->id}/notes/{$otherNote->id}")
            ->assertNotFound();
        $this->putJson("/api/workspaces/{$workspace->id}/notes/{$otherNote->id}", ['content' => '# Nope'])
            ->assertNotFound();
        $this->deleteJson("/api/workspaces/{$workspace->id}/notes/{$otherNote->id}")
            ->assertNotFound();

        $this->postJson("/api/workspaces/{$workspace->id}/notes", [
            'path' => '../../outside.md',
            'content' => '# Unsafe',
        ])->assertUnprocessable()->assertJsonValidationErrors('path');

        $this->assertDatabaseHas('notes', ['id' => $otherNote->id, 'workspace_id' => $otherWorkspace->id]);
        $this->assertFileDoesNotExist(dirname($this->vaultRoot).'/outside.md');
        $this->assertDatabaseHas('audit_log', [
            'workspace_id' => $workspace->id,
            'event' => VaultPathGuard::AUDIT_EVENT,
        ]);
    }

    public function test_note_routes_fail_closed_until_pr7_configures_an_identity_provider(): void
    {
        $workspace = $this->makeWorkspace('protected');

        $this->getJson("/api/workspaces/{$workspace->id}/notes")
            ->assertUnauthorized();
    }

    private function createNote(Workspace $workspace, string $path, string $content): Note
    {
        $response = $this->postJson("/api/workspaces/{$workspace->id}/notes", compact('path', 'content'))
            ->assertCreated();

        return Note::query()->findOrFail($response->json('data.id'));
    }

    private function makeWorkspace(string $suffix): Workspace
    {
        $tenant = Tenant::query()->create([
            'slug' => "notes-api-tenant-{$suffix}-".uniqid(),
            'name' => 'Notes API Tenant',
        ]);

        return Workspace::query()->create([
            'tenant_id' => $tenant->id,
            'slug' => "notes-api-ws-{$suffix}-".uniqid(),
            'name' => 'Notes API Workspace',
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
