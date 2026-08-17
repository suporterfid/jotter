<?php

namespace Tests\Feature;

use App\Domain\Vault\VaultStorage;
use App\Models\Membership;
use App\Models\Note;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceRoleAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $vaultRoots = [];

    protected function tearDown(): void
    {
        foreach ($this->vaultRoots as $vaultRoot) {
            $this->deleteTree($vaultRoot);
        }

        parent::tearDown();
    }

    public function test_viewer_can_read_but_cannot_update_note(): void
    {
        [$user, $workspace, $note] = $this->workspaceNoteForRole('viewer');
        $original = file_get_contents($workspace->vault_path.'/note.md');

        $response = $this->actingAs($user)->putJson(
            "/api/workspaces/{$workspace->id}/notes/{$note->id}",
            ['content' => '# Alteração proibida'],
        );

        $response->assertForbidden()
            ->assertJsonPath('message', __('messages.forbidden'));

        $this->assertSame($original, file_get_contents($workspace->vault_path.'/note.md'));
        $this->assertDatabaseHas('notes', [
            'id' => $note->id,
            'title' => 'Original',
        ]);
        $this->assertDatabaseHas('audit_log', [
            'workspace_id' => $workspace->id,
            'event' => 'auth.forbidden',
        ]);

        $this->actingAs($user)
            ->getJson("/api/workspaces/{$workspace->id}/notes")
            ->assertOk();
    }

    public function test_viewer_cannot_create_or_delete_notes(): void
    {
        [$user, $workspace, $note] = $this->workspaceNoteForRole('viewer');

        $this->actingAs($user)
            ->postJson("/api/workspaces/{$workspace->id}/notes", [
                'path' => 'created.md',
                'content' => '# Created',
            ])
            ->assertForbidden();

        $this->actingAs($user)
            ->deleteJson("/api/workspaces/{$workspace->id}/notes/{$note->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('notes', ['id' => $note->id]);
        $this->assertFileExists($workspace->vault_path.'/note.md');
        $this->assertFileDoesNotExist($workspace->vault_path.'/created.md');
    }

    public function test_editor_can_update_a_note(): void
    {
        [$user, $workspace, $note] = $this->workspaceNoteForRole('editor');

        $this->actingAs($user)
            ->putJson("/api/workspaces/{$workspace->id}/notes/{$note->id}", [
                'content' => '# Editor update',
            ])
            ->assertOk();

        $this->assertSame('# Editor update', file_get_contents($workspace->vault_path.'/note.md'));
    }

    public function test_viewer_cannot_mutate_other_workspace_surfaces(): void
    {
        [$user, $workspace, $note] = $this->workspaceNoteForRole('viewer');
        $client = $this->actingAs($user);

        $requests = [
            fn () => $client->postJson("/api/workspaces/{$workspace->id}/notes/{$note->id}/comments", []),
            fn () => $client->postJson("/api/workspaces/{$workspace->id}/notes/{$note->id}/checklist-items", []),
            fn () => $client->postJson("/api/workspaces/{$workspace->id}/boards", []),
            fn () => $client->postJson("/api/workspaces/{$workspace->id}/notes/{$note->id}/properties", []),
            fn () => $client->postJson("/api/workspaces/{$workspace->id}/attachments", []),
            fn () => $client->postJson("/api/workspaces/{$workspace->id}/publish", []),
            fn () => $client->postJson("/api/workspaces/{$workspace->id}/import", []),
            fn () => $client->putJson("/api/workspaces/{$workspace->id}/note-tree/order", []),
            fn () => $client->postJson("/api/workspaces/{$workspace->id}/notes/{$note->id}/move", []),
        ];

        foreach ($requests as $request) {
            $request()->assertForbidden();
        }
    }

    public function test_viewer_cannot_restore_or_permanently_delete_trash_entries(): void
    {
        [$user, $workspace, $note] = $this->workspaceNoteForRole('viewer');
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->deleteJson("/api/workspaces/{$workspace->id}/notes/{$note->id}")
            ->assertNoContent();

        $client = $this->actingAs($user);
        $client
            ->postJson("/api/workspaces/{$workspace->id}/trash/{$note->id}/restore")
            ->assertForbidden();
        $client
            ->deleteJson("/api/workspaces/{$workspace->id}/trash/{$note->id}")
            ->assertForbidden();

        $this->assertTrue(Note::withTrashed()->findOrFail($note->id)->trashed());
    }

    /**
     * @return array{0: User, 1: Workspace, 2: Note}
     */
    private function workspaceNoteForRole(string $role): array
    {
        $vaultRoot = sys_get_temp_dir().'/jotter-role-auth-'.uniqid('', true);
        mkdir($vaultRoot, 0755, true);
        $this->vaultRoots[] = $vaultRoot;

        $user = User::factory()->create(['is_admin' => false]);
        $tenant = Tenant::create([
            'slug' => "role-auth-tenant-{$role}",
            'name' => 'Role Auth Tenant',
        ]);
        $workspace = Workspace::create([
            'tenant_id' => $tenant->id,
            'slug' => "role-auth-workspace-{$role}",
            'name' => 'Role Auth Workspace',
            'vault_path' => $vaultRoot,
        ]);

        Membership::create([
            'subject_id' => (string) $user->id,
            'tenant_id' => $tenant->id,
            'workspace_id' => $workspace->id,
            'role' => $role,
        ]);

        $note = app(VaultStorage::class)->write($workspace, 'note.md', "# Original\n");

        return [$user, $workspace, $note];
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
