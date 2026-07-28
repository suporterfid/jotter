<?php

namespace Tests\Feature;

use App\Domain\Vault\VaultStorage;
use App\Models\NoteComment;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class WorkspaceCommentAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_workspace_member_cannot_delete_another_members_comment(): void
    {
        [$workspace, $note, $author, $otherMember] = $this->makeWorkspaceWithMembers();

        $comment = NoteComment::query()->create([
            'workspace_id' => $workspace->id,
            'note_id' => $note->id,
            'user_id' => $author->id,
            'actor_name' => $author->name,
            'content' => 'Original author comment.',
        ]);

        $this->actingAs($otherMember)
            ->deleteJson("/api/workspaces/{$workspace->id}/notes/{$note->id}/comments/{$comment->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('note_comments', ['id' => $comment->id]);
    }

    public function test_the_comment_author_can_delete_their_own_comment(): void
    {
        [$workspace, $note, $author] = $this->makeWorkspaceWithMembers();

        $comment = NoteComment::query()->create([
            'workspace_id' => $workspace->id,
            'note_id' => $note->id,
            'user_id' => $author->id,
            'actor_name' => $author->name,
            'content' => 'Original author comment.',
        ]);

        $this->actingAs($author)
            ->deleteJson("/api/workspaces/{$workspace->id}/notes/{$note->id}/comments/{$comment->id}")
            ->assertOk();

        $this->assertDatabaseMissing('note_comments', ['id' => $comment->id]);
    }

    public function test_an_admin_can_delete_any_members_comment(): void
    {
        [$workspace, $note, $author, , $admin] = $this->makeWorkspaceWithMembers();

        $comment = NoteComment::query()->create([
            'workspace_id' => $workspace->id,
            'note_id' => $note->id,
            'user_id' => $author->id,
            'actor_name' => $author->name,
            'content' => 'Original author comment.',
        ]);

        $this->actingAs($admin)
            ->deleteJson("/api/workspaces/{$workspace->id}/notes/{$note->id}/comments/{$comment->id}")
            ->assertOk();

        $this->assertDatabaseMissing('note_comments', ['id' => $comment->id]);
    }

    /**
     * @return array{0: Workspace, 1: \App\Models\Note, 2: User, 3: User, 4: User}
     */
    private function makeWorkspaceWithMembers(): array
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $author = User::factory()->create(['is_admin' => false]);
        $otherMember = User::factory()->create(['is_admin' => false]);

        $tenant = Tenant::create(['slug' => 'comment-authz-'.uniqid(), 'name' => 'Comment Authz Tenant']);
        $vaultPath = storage_path('app/vaults/comment_authz_'.uniqid());
        mkdir($vaultPath, 0755, true);

        $workspace = Workspace::create([
            'tenant_id' => $tenant->id,
            'slug' => 'comment-authz-ws-'.uniqid(),
            'name' => 'Comment Authz Workspace',
            'vault_path' => $vaultPath,
        ]);

        foreach ([$author, $otherMember] as $member) {
            $workspace->memberships()->create([
                'tenant_id' => $tenant->id,
                'subject_id' => (string) $member->id,
                'user_id' => $member->id,
                'role' => 'member',
            ]);
        }

        /** @var VaultStorage $storage */
        $storage = $this->app->make(VaultStorage::class);
        $note = $storage->write($workspace, 'note.md', "# Note\nBody.");

        return [$workspace, $note, $author, $otherMember, $admin];
    }
}
