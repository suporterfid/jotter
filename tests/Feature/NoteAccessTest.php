<?php

namespace Tests\Feature;

use App\Domain\Auth\AuthenticatedSubject;
use App\Domain\Auth\NoteAccess;
use App\Models\Membership;
use App\Models\Note;
use App\Models\NoteAclEntry;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceGroup;
use App\Domain\Vault\VaultStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NoteAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_groups_and_acl_entries_are_workspace_scoped(): void
    {
        [$workspace, $note] = $this->workspaceAndNote();
        $user = User::factory()->create();
        $group = WorkspaceGroup::create([
            'workspace_id' => $workspace->id,
            'name' => 'Editors',
        ]);

        $group->members()->attach($user->id);
        $entry = NoteAclEntry::create([
            'note_id' => $note->id,
            'principal_type' => 'group',
            'principal_id' => $group->id,
            'permission' => 'edit',
        ]);

        $this->assertSame($workspace->id, $group->workspace->id);
        $this->assertTrue($group->members->contains($user));
        $this->assertSame($note->id, $entry->note->id);
        $this->assertTrue($group->aclEntries->contains($entry));
        $this->assertCount(1, $note->aclEntries);
    }

    public function test_zero_acl_rows_mean_inherited_access_and_edit_implies_view(): void
    {
        [$workspace, $note] = $this->workspaceAndNote();
        $user = User::factory()->create();
        Membership::create([
            'subject_id' => (string) $user->id,
            'tenant_id' => $workspace->tenant_id,
            'workspace_id' => $workspace->id,
            'role' => 'editor',
        ]);
        $subject = new AuthenticatedSubject((string) $user->id, $user->email, $user->name, false, $user);

        $access = app(NoteAccess::class);

        $this->assertFalse($access->isRestricted($note));
        $this->assertTrue($access->canView($subject, $note));
        $this->assertTrue($access->canEdit($subject, $note));

        NoteAclEntry::create([
            'note_id' => $note->id,
            'principal_type' => 'user',
            'principal_id' => $user->id,
            'permission' => 'edit',
        ]);

        $this->assertTrue($access->isRestricted($note->fresh()));
        $this->assertTrue($access->canView($subject, $note->fresh()));
        $this->assertTrue($access->canEdit($subject, $note->fresh()));
    }

    public function test_cross_workspace_group_is_not_a_matching_grant(): void
    {
        [$workspace, $note] = $this->workspaceAndNote();
        [$otherWorkspace] = $this->workspaceAndNote('other');
        $user = User::factory()->create();
        Membership::create([
            'subject_id' => (string) $user->id,
            'tenant_id' => $workspace->tenant_id,
            'workspace_id' => $workspace->id,
            'role' => 'viewer',
        ]);
        $group = WorkspaceGroup::create([
            'workspace_id' => $otherWorkspace->id,
            'name' => 'Other group',
        ]);
        $group->members()->attach($user->id);
        NoteAclEntry::create([
            'note_id' => $note->id,
            'principal_type' => 'group',
            'principal_id' => $group->id,
            'permission' => 'view',
        ]);

        $subject = new AuthenticatedSubject((string) $user->id, $user->email, $user->name, false, $user);

        $this->assertFalse(app(NoteAccess::class)->canView($subject, $note->fresh()));
    }

    public function test_direct_grants_and_workspace_roles_are_enforced(): void
    {
        [$workspace, $note] = $this->workspaceAndNote('direct');
        $viewer = User::factory()->create();
        $editor = User::factory()->create();
        $owner = User::factory()->create();
        $this->membership($viewer, $workspace, 'viewer');
        $this->membership($editor, $workspace, 'editor');
        $this->membership($owner, $workspace, 'owner');
        NoteAclEntry::create([
            'note_id' => $note->id,
            'principal_type' => 'user',
            'principal_id' => $viewer->id,
            'permission' => 'view',
        ]);
        NoteAclEntry::create([
            'note_id' => $note->id,
            'principal_type' => 'user',
            'principal_id' => $editor->id,
            'permission' => 'edit',
        ]);

        $access = app(NoteAccess::class);
        $viewerSubject = $this->subject($viewer);
        $editorSubject = $this->subject($editor);
        $ownerSubject = $this->subject($owner);

        $this->assertTrue($access->canView($viewerSubject, $note->fresh()));
        $this->assertFalse($access->canEdit($viewerSubject, $note->fresh()));
        $this->assertTrue($access->canView($editorSubject, $note->fresh()));
        $this->assertTrue($access->canEdit($editorSubject, $note->fresh()));
        $this->assertTrue($access->canView($ownerSubject, $note->fresh()));
        $this->assertTrue($access->canEdit($ownerSubject, $note->fresh()));

        $admin = User::factory()->create(['is_admin' => true]);
        $this->assertTrue($access->canView($this->subject($admin, true), $note->fresh()));
        $this->assertTrue($access->canEdit($this->subject($admin, true), $note->fresh()));
    }

    public function test_service_token_can_only_read_unrestricted_notes(): void
    {
        [$workspace, $note] = $this->workspaceAndNote('service');
        $subject = new AuthenticatedSubject(
            'service:reader',
            '',
            'Service reader',
            false,
            null,
            ['auth_method' => 'grandpasson_service_token', 'audiences' => ["workspace/{$workspace->id}"]],
        );
        $access = app(NoteAccess::class);

        $this->assertTrue($access->canView($subject, $note));
        $this->assertFalse($access->canEdit($subject, $note));

        NoteAclEntry::create([
            'note_id' => $note->id,
            'principal_type' => 'user',
            'principal_id' => User::factory()->create()->id,
            'permission' => 'view',
        ]);

        $this->assertFalse($access->canView($subject, $note->fresh()));
    }

    public function test_visibility_scopes_filter_restricted_notes_without_per_note_queries(): void
    {
        [$workspace, $visible] = $this->workspaceAndNote('scope-visible');
        $hidden = app(VaultStorage::class)->write($workspace, 'hidden.md', "# Hidden\n");
        $user = User::factory()->create();
        $this->membership($user, $workspace, 'editor');
        NoteAclEntry::create([
            'note_id' => $hidden->id,
            'principal_type' => 'user',
            'principal_id' => User::factory()->create()->id,
            'permission' => 'view',
        ]);

        $notes = app(NoteAccess::class)->scopeVisible(
            Note::query(),
            $this->subject($user),
            $workspace->id,
        )->pluck('id')->all();

        $this->assertSame([$visible->id], $notes);
    }

    public function test_acl_and_group_rows_cascade_with_their_parent_scope(): void
    {
        [$workspace, $note] = $this->workspaceAndNote();
        $group = WorkspaceGroup::create(['workspace_id' => $workspace->id, 'name' => 'Readers']);
        NoteAclEntry::create([
            'note_id' => $note->id,
            'principal_type' => 'group',
            'principal_id' => $group->id,
            'permission' => 'view',
        ]);

        $note->forceDelete();
        $this->assertDatabaseMissing('note_acl_entries', ['note_id' => $note->id]);

        $workspace->delete();
        $this->assertDatabaseMissing('workspace_groups', ['id' => $group->id]);
    }

    /** @return array{0: Workspace, 1: Note} */
    private function workspaceAndNote(string $suffix = 'primary'): array
    {
        $tenant = Tenant::create([
            'slug' => 'acl-tenant-'.$suffix.'-'.uniqid(),
            'name' => 'ACL Tenant',
        ]);
        $workspace = Workspace::create([
            'tenant_id' => $tenant->id,
            'slug' => 'acl-workspace-'.$suffix.'-'.uniqid(),
            'name' => 'ACL Workspace',
            'vault_path' => sys_get_temp_dir().'/jotter-acl-'.uniqid('', true),
        ]);
        mkdir($workspace->vault_path, 0755, true);
        $note = app(VaultStorage::class)->write($workspace, 'note.md', "# Note\n");

        return [$workspace, $note];
    }

    private function membership(User $user, Workspace $workspace, string $role): Membership
    {
        return Membership::create([
            'subject_id' => (string) $user->id,
            'tenant_id' => $workspace->tenant_id,
            'workspace_id' => $workspace->id,
            'role' => $role,
        ]);
    }

    private function subject(User $user, bool $isAdmin = false): AuthenticatedSubject
    {
        return new AuthenticatedSubject((string) $user->id, $user->email, $user->name, $isAdmin, $user);
    }
}
