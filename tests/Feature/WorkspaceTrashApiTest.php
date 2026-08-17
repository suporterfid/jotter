<?php

namespace Tests\Feature;

use App\Domain\Vault\VaultStorage;
use App\Models\Note;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class WorkspaceTrashApiTest extends TestCase
{
    use RefreshDatabase;

    private string $vaultRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vaultRoot = sys_get_temp_dir().'/jotter-trash-api-'.uniqid('', true);
        mkdir($this->vaultRoot, 0755, true);
        $this->actingAs(User::factory()->create(['is_admin' => true]));
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->vaultRoot);

        parent::tearDown();
    }

    public function test_deleted_note_is_listed_in_trash_without_exposing_internal_path(): void
    {
        $workspace = $this->makeWorkspace();
        $note = app(VaultStorage::class)->write($workspace, 'deleted.md', "# Deleted\n");

        $this->deleteJson("/api/workspaces/{$workspace->id}/notes/{$note->id}")
            ->assertNoContent();

        $this->getJson("/api/workspaces/{$workspace->id}/notes")
            ->assertOk()
            ->assertJsonPath('data', []);

        $this->getJson("/api/workspaces/{$workspace->id}/trash")
            ->assertOk()
            ->assertJsonPath('data.0.id', $note->id)
            ->assertJsonPath('data.0.original_path', 'deleted.md')
            ->assertJsonMissingPath('data.0.path');
    }

    public function test_restore_returns_the_note_to_its_original_path(): void
    {
        $workspace = $this->makeWorkspace();
        $note = app(VaultStorage::class)->write($workspace, 'restore.md', "# Restore\n");
        $this->deleteJson("/api/workspaces/{$workspace->id}/notes/{$note->id}")->assertNoContent();

        $this->postJson("/api/workspaces/{$workspace->id}/trash/{$note->id}/restore")
            ->assertOk()
            ->assertJsonPath('data.id', $note->id)
            ->assertJsonPath('data.path', 'restore.md');

        $this->assertFileExists($this->vaultRoot.'/workspace/restore.md');
        $this->assertDatabaseHas('notes', ['id' => $note->id, 'deleted_at' => null, 'path' => 'restore.md']);
    }

    public function test_restore_returns_conflict_when_original_path_is_occupied(): void
    {
        $workspace = $this->makeWorkspace();
        $storage = app(VaultStorage::class);
        $note = $storage->write($workspace, 'same.md', "# First\n");
        $this->deleteJson("/api/workspaces/{$workspace->id}/notes/{$note->id}")->assertNoContent();
        $storage->write($workspace, 'same.md', "# Replacement\n");

        $this->postJson("/api/workspaces/{$workspace->id}/trash/{$note->id}/restore")
            ->assertStatus(409)
            ->assertJsonPath('message', 'The original note path is already occupied.');

        $this->assertDatabaseHas('notes', ['id' => $note->id]);
        $this->assertTrue(Note::withTrashed()->findOrFail($note->id)->trashed());
    }

    public function test_permanent_delete_removes_the_trash_entry_and_file(): void
    {
        $workspace = $this->makeWorkspace();
        $note = app(VaultStorage::class)->write($workspace, 'permanent.md', "# Permanent\n");
        $this->deleteJson("/api/workspaces/{$workspace->id}/notes/{$note->id}")->assertNoContent();
        $trashed = Note::withTrashed()->findOrFail($note->id);
        $trashPath = $this->vaultRoot.'/'.$trashed->path;

        $this->deleteJson("/api/workspaces/{$workspace->id}/trash/{$note->id}")
            ->assertNoContent();

        $this->assertFileDoesNotExist($trashPath);
        $this->assertDatabaseMissing('notes', ['id' => $note->id]);
    }

    private function makeWorkspace(): Workspace
    {
        $tenant = Tenant::query()->create([
            'slug' => 'trash-api-tenant-'.uniqid(),
            'name' => 'Trash API Tenant',
        ]);

        return Workspace::query()->create([
            'tenant_id' => $tenant->id,
            'slug' => 'trash-api-workspace-'.uniqid(),
            'name' => 'Trash API Workspace',
            'vault_path' => $this->vaultRoot.'/workspace',
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
