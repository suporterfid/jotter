<?php

namespace Tests\Feature;

use App\Models\FolderPosition;
use App\Models\Note;
use App\Models\Tenant;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceNoteTreeOrderTest extends TestCase
{
    use RefreshDatabase;

    private string $vaultRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vaultRoot = sys_get_temp_dir().'/jotter-tree-order-'.uniqid('', true);
        mkdir($this->vaultRoot, 0755, true);

        $admin = \App\Models\User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->vaultRoot);
        parent::tearDown();
    }

    public function test_reorder_persists_sequential_positions_for_notes_and_folders(): void
    {
        $workspace = $this->makeWorkspace('reorder');
        $storage = app(\App\Domain\Vault\VaultStorage::class);

        $noteA = $storage->write($workspace, 'docs/a.md', "# A\n");
        $noteB = $storage->write($workspace, 'docs/b.md', "# B\n");
        $storage->write($workspace, 'docs/archived/c.md', "# C\n");

        $response = $this->putJson("/api/workspaces/{$workspace->id}/note-tree/order", [
            'folder_path' => 'docs',
            'items' => [
                ['type' => 'folder', 'path' => 'docs/archived'],
                ['type' => 'note', 'id' => $noteB->id],
                ['type' => 'note', 'id' => $noteA->id],
            ],
        ]);

        $response->assertNoContent();

        $this->assertSame(0, FolderPosition::where('workspace_id', $workspace->id)
            ->where('folder_path', 'docs/archived')->value('sort_position'));
        $this->assertSame(10, Note::find($noteB->id)->sort_position);
        $this->assertSame(20, Note::find($noteA->id)->sort_position);
    }

    public function test_reorder_rejects_incomplete_item_list(): void
    {
        $workspace = $this->makeWorkspace('incomplete');
        $storage = app(\App\Domain\Vault\VaultStorage::class);

        $noteA = $storage->write($workspace, 'a.md', "# A\n");
        $storage->write($workspace, 'b.md', "# B\n");

        $response = $this->putJson("/api/workspaces/{$workspace->id}/note-tree/order", [
            'folder_path' => '',
            'items' => [
                ['type' => 'note', 'id' => $noteA->id],
            ],
        ]);

        $response->assertStatus(422);
    }

    public function test_reorder_rejects_note_from_another_workspace(): void
    {
        $workspace = $this->makeWorkspace('scope-a');
        $otherWorkspace = $this->makeWorkspace('scope-b');
        $storage = app(\App\Domain\Vault\VaultStorage::class);

        $note = $storage->write($workspace, 'a.md', "# A\n");
        $foreignNote = $storage->write($otherWorkspace, 'foreign.md', "# Foreign\n");

        $response = $this->putJson("/api/workspaces/{$workspace->id}/note-tree/order", [
            'folder_path' => '',
            'items' => [
                ['type' => 'note', 'id' => $note->id],
                ['type' => 'note', 'id' => $foreignNote->id],
            ],
        ]);

        $response->assertStatus(422);
    }

    public function test_index_returns_only_this_workspace_folder_positions(): void
    {
        $workspace = $this->makeWorkspace('list-a');
        $otherWorkspace = $this->makeWorkspace('list-b');

        FolderPosition::create(['workspace_id' => $workspace->id, 'folder_path' => 'docs', 'sort_position' => 0]);
        FolderPosition::create(['workspace_id' => $otherWorkspace->id, 'folder_path' => 'other', 'sort_position' => 0]);

        $response = $this->getJson("/api/workspaces/{$workspace->id}/note-tree/order");

        $response->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.folder_path', 'docs');
    }

    private function makeWorkspace(string $suffix): Workspace
    {
        $tenant = Tenant::query()->create([
            'slug' => "tree-order-tenant-{$suffix}-".uniqid(),
            'name' => 'Tree Order Tenant',
        ]);

        return Workspace::query()->create([
            'tenant_id' => $tenant->id,
            'slug' => "tree-order-ws-{$suffix}-".uniqid(),
            'name' => 'Tree Order Workspace',
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
        foreach (scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $this->deleteTree($path.'/'.$entry);
        }
        @rmdir($path);
    }
}
