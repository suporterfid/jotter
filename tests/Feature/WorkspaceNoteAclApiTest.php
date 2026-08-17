<?php

namespace Tests\Feature;

use App\Domain\Vault\VaultStorage;
use App\Models\Membership;
use App\Models\Note;
use App\Models\NoteAclEntry;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceNoteAclApiTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $vaultRoots = [];

    protected function tearDown(): void
    {
        foreach ($this->vaultRoots as $root) {
            $this->deleteTree($root);
        }
        parent::tearDown();
    }

    public function test_workspace_admin_can_replace_acl_and_empty_entries_restore_inheritance(): void
    {
        [$workspace, $note, $admin] = $this->workspaceNote('admin', 'admin');
        $reader = User::factory()->create();
        $this->membership($reader, $workspace, 'viewer');

        $this->actingAs($admin)
            ->putJson("/api/workspaces/{$workspace->id}/notes/{$note->id}/acl", [
                'entries' => [[
                    'principal_type' => 'user',
                    'principal_id' => $reader->id,
                    'permission' => 'view',
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('data.restricted', true)
            ->assertJsonPath('data.entries.0.principal_id', $reader->id);

        $this->assertDatabaseHas('note_acl_entries', [
            'note_id' => $note->id,
            'principal_type' => 'user',
            'principal_id' => $reader->id,
            'permission' => 'view',
        ]);

        $this->actingAs($admin)
            ->putJson("/api/workspaces/{$workspace->id}/notes/{$note->id}/acl", ['entries' => []])
            ->assertOk()
            ->assertJsonPath('data.restricted', false);

        $this->assertDatabaseMissing('note_acl_entries', ['note_id' => $note->id]);
    }

    public function test_hidden_note_returns_not_found_and_acl_changes_are_admin_only(): void
    {
        [$workspace, $note, $admin] = $this->workspaceNote('hidden', 'admin');
        $viewer = User::factory()->create();
        $this->membership($viewer, $workspace, 'viewer');
        NoteAclEntry::create([
            'note_id' => $note->id,
            'principal_type' => 'user',
            'principal_id' => User::factory()->create()->id,
            'permission' => 'view',
        ]);

        $this->actingAs($viewer)
            ->getJson("/api/workspaces/{$workspace->id}/notes/{$note->id}")
            ->assertNotFound();

        $this->actingAs($viewer)
            ->putJson("/api/workspaces/{$workspace->id}/notes/{$note->id}/acl", ['entries' => []])
            ->assertForbidden();

        $this->actingAs($admin)
            ->getJson("/api/workspaces/{$workspace->id}/notes/{$note->id}/acl")
            ->assertOk()
            ->assertJsonPath('data.restricted', true);
    }

    public function test_group_grant_is_accepted_only_for_a_group_in_the_same_workspace(): void
    {
        [$workspace, $note, $admin] = $this->workspaceNote('group', 'owner');
        $group = WorkspaceGroup::create(['workspace_id' => $workspace->id, 'name' => 'Readers']);

        $this->actingAs($admin)
            ->putJson("/api/workspaces/{$workspace->id}/notes/{$note->id}/acl", [
                'entries' => [[
                    'principal_type' => 'group',
                    'principal_id' => $group->id,
                    'permission' => 'edit',
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('data.entries.0.principal_type', 'group');

        [$otherWorkspace] = $this->workspaceNote('other-group', 'owner');
        $otherGroup = WorkspaceGroup::create(['workspace_id' => $otherWorkspace->id, 'name' => 'Other']);

        $this->actingAs($admin)
            ->putJson("/api/workspaces/{$workspace->id}/notes/{$note->id}/acl", [
                'entries' => [[
                    'principal_type' => 'group',
                    'principal_id' => $otherGroup->id,
                    'permission' => 'view',
                ]],
            ])
            ->assertUnprocessable();
    }

    /** @return array{0: Workspace, 1: Note, 2: User} */
    private function workspaceNote(string $suffix, string $role): array
    {
        $tenant = Tenant::create(['slug' => 'acl-api-'.$suffix.'-'.uniqid(), 'name' => 'ACL API']);
        $workspace = Workspace::create([
            'tenant_id' => $tenant->id,
            'slug' => 'acl-api-workspace-'.$suffix.'-'.uniqid(),
            'name' => 'ACL API Workspace',
            'vault_path' => sys_get_temp_dir().'/jotter-acl-api-'.uniqid('', true),
        ]);
        mkdir($workspace->vault_path, 0755, true);
        $this->vaultRoots[] = $workspace->vault_path;
        $admin = User::factory()->create();
        $this->membership($admin, $workspace, $role);
        $note = app(VaultStorage::class)->write($workspace, 'note.md', "# Note\n");

        return [$workspace, $note, $admin];
    }

    private function membership(User $user, Workspace $workspace, string $role): void
    {
        Membership::create([
            'subject_id' => (string) $user->id,
            'tenant_id' => $workspace->tenant_id,
            'workspace_id' => $workspace->id,
            'role' => $role,
        ]);
    }

    private function deleteTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $child = $path.DIRECTORY_SEPARATOR.$item;
            is_dir($child) ? $this->deleteTree($child) : @unlink($child);
        }
        @rmdir($path);
    }
}
