<?php

namespace Tests\Feature;

use App\Domain\Vault\VaultStorage;
use App\Models\Notification;
use App\Models\NoteAclEntry;
use App\Models\NoteComment;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class WorkspaceNotificationWatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_watch_and_unwatch_a_note_and_metadata_reflects_state(): void
    {
        $owner = User::factory()->create(['is_admin' => true]);
        $watcher = User::factory()->create();
        [$workspace, $note] = $this->makeNote($owner, $watcher);

        $this->actingAs($watcher)
            ->getJson("/api/workspaces/{$workspace->id}/notes/{$note->id}")
            ->assertOk()
            ->assertJsonPath('data.watching', false);

        $this->actingAs($watcher)
            ->putJson("/api/workspaces/{$workspace->id}/notes/{$note->id}/watch", ['watching' => true])
            ->assertOk()
            ->assertJsonPath('data.watching', true);

        $this->actingAs($watcher)
            ->getJson("/api/workspaces/{$workspace->id}/notes")
            ->assertOk()
            ->assertJsonPath('data.0.watching', true);

        $this->actingAs($watcher)
            ->putJson("/api/workspaces/{$workspace->id}/notes/{$note->id}/watch", ['watching' => false])
            ->assertOk()
            ->assertJsonPath('data.watching', false);
    }

    public function test_note_edit_notifies_active_watchers_but_not_actor_and_records_an_audit_event(): void
    {
        $owner = User::factory()->create(['is_admin' => true]);
        $watcher = User::factory()->create();
        [$workspace, $note] = $this->makeNote($owner, $watcher);

        $this->actingAs($watcher)
            ->putJson("/api/workspaces/{$workspace->id}/notes/{$note->id}/watch", ['watching' => true])
            ->assertOk();

        $this->actingAs($owner)
            ->putJson("/api/workspaces/{$workspace->id}/notes/{$note->id}", ['content' => '# Updated'])
            ->assertOk();

        $this->assertDatabaseHas('notifications', [
            'workspace_id' => $workspace->id,
            'user_id' => $watcher->id,
            'type' => 'note_edited',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'workspace_id' => $workspace->id,
            'user_id' => $owner->id,
            'type' => 'note_edited',
        ]);
        $this->assertDatabaseHas('audit_log', [
            'workspace_id' => $workspace->id,
            'event' => 'note.updated',
        ]);
        $this->assertSame(1, Notification::query()->where('type', 'note_edited')->count());
    }

    public function test_explicit_unwatch_is_not_reenabled_by_a_later_edit(): void
    {
        $owner = User::factory()->create(['is_admin' => true]);
        $watcher = User::factory()->create();
        [$workspace, $note] = $this->makeNote($owner, $watcher);

        $this->actingAs($watcher)
            ->putJson("/api/workspaces/{$workspace->id}/notes/{$note->id}/watch", ['watching' => true])
            ->assertOk();
        $this->actingAs($watcher)
            ->putJson("/api/workspaces/{$workspace->id}/notes/{$note->id}/watch", ['watching' => false])
            ->assertOk();

        $this->actingAs($owner)
            ->putJson("/api/workspaces/{$workspace->id}/notes/{$note->id}", ['content' => '# Changed'])
            ->assertOk();

        $this->assertDatabaseMissing('notifications', [
            'user_id' => $watcher->id,
            'type' => 'note_edited',
        ]);
        $this->assertDatabaseHas('note_watchers', [
            'note_id' => $note->id,
            'user_id' => $watcher->id,
            'is_watching' => 0,
        ]);
    }

    public function test_comment_auto_watches_actor_and_notifies_an_existing_watcher(): void
    {
        $owner = User::factory()->create(['is_admin' => true]);
        $watcher = User::factory()->create();
        [$workspace, $note] = $this->makeNote($owner, $watcher);

        $this->actingAs($watcher)
            ->putJson("/api/workspaces/{$workspace->id}/notes/{$note->id}/watch", ['watching' => true])
            ->assertOk();

        $this->actingAs($owner)
            ->postJson("/api/workspaces/{$workspace->id}/notes/{$note->id}/comments", ['content' => 'Please review'])
            ->assertCreated();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $watcher->id,
            'type' => 'note_commented',
        ]);
        $this->assertDatabaseHas('note_watchers', [
            'note_id' => $note->id,
            'user_id' => $owner->id,
            'is_watching' => 1,
        ]);
    }

    public function test_reply_notifies_thread_participant_once_and_persists_parent_comment(): void
    {
        $owner = User::factory()->create(['is_admin' => true]);
        $participant = User::factory()->create();
        [$workspace, $note] = $this->makeNote($owner, $participant);
        $this->actingAs($participant)
            ->putJson("/api/workspaces/{$workspace->id}/notes/{$note->id}/watch", ['watching' => true])
            ->assertOk();
        $parent = NoteComment::query()->create([
            'workspace_id' => $workspace->id,
            'note_id' => $note->id,
            'user_id' => $participant->id,
            'actor_name' => $participant->name,
            'content' => 'Original thread comment',
        ]);

        $response = $this->actingAs($owner)
            ->postJson("/api/workspaces/{$workspace->id}/notes/{$note->id}/comments", [
                'content' => 'Replying here',
                'parent_comment_id' => $parent->id,
            ]);

        $response->assertCreated()->assertJsonPath('data.parent_comment_id', $parent->id);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $participant->id,
            'type' => 'comment_reply',
        ]);
        $this->assertSame(1, Notification::query()
            ->where('user_id', $participant->id)
            ->where('type', 'comment_reply')
            ->count());
    }

    public function test_move_and_delete_emit_distinct_notifications_to_watchers(): void
    {
        $owner = User::factory()->create(['is_admin' => true]);
        $watcher = User::factory()->create();
        [$workspace, $note] = $this->makeNote($owner, $watcher);
        $this->actingAs($watcher)
            ->putJson("/api/workspaces/{$workspace->id}/notes/{$note->id}/watch", ['watching' => true])
            ->assertOk();

        $moved = $this->actingAs($owner)
            ->postJson("/api/workspaces/{$workspace->id}/notes/{$note->id}/move", ['new_path' => 'renamed.md']);
        $moved->assertOk();
        $movedNoteId = $moved->json('data.id');

        $this->actingAs($owner)
            ->deleteJson("/api/workspaces/{$workspace->id}/notes/{$movedNoteId}")
            ->assertNoContent();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $watcher->id,
            'type' => 'note_moved',
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $watcher->id,
            'type' => 'note_deleted',
        ]);
        $this->assertSame('watched.md', Notification::query()
            ->where('user_id', $watcher->id)
            ->where('type', 'note_moved')
            ->firstOrFail()
            ->data['old_path']);
    }

    public function test_rapid_edits_coalesce_per_watcher_and_note(): void
    {
        $owner = User::factory()->create(['is_admin' => true]);
        $watcher = User::factory()->create();
        [$workspace, $note] = $this->makeNote($owner, $watcher);
        $this->actingAs($watcher)
            ->putJson("/api/workspaces/{$workspace->id}/notes/{$note->id}/watch", ['watching' => true])
            ->assertOk();

        $this->actingAs($owner)->putJson("/api/workspaces/{$workspace->id}/notes/{$note->id}", ['content' => '# One'])->assertOk();
        $this->actingAs($owner)->putJson("/api/workspaces/{$workspace->id}/notes/{$note->id}", ['content' => '# Two'])->assertOk();

        $this->assertSame(1, Notification::query()
            ->where('user_id', $watcher->id)
            ->where('type', 'note_edited')
            ->count());
    }

    public function test_restricted_note_does_not_leak_events_to_a_watcher_without_view_access(): void
    {
        $owner = User::factory()->create(['is_admin' => true]);
        $watcher = User::factory()->create();
        [$workspace, $note] = $this->makeNote($owner, $watcher);
        $this->actingAs($watcher)
            ->putJson("/api/workspaces/{$workspace->id}/notes/{$note->id}/watch", ['watching' => true])
            ->assertOk();

        NoteAclEntry::query()->create([
            'note_id' => $note->id,
            'principal_type' => 'user',
            'principal_id' => $owner->id,
            'permission' => 'view',
        ]);

        $this->actingAs($owner)
            ->putJson("/api/workspaces/{$workspace->id}/notes/{$note->id}", ['content' => '# Restricted'])
            ->assertOk();

        $this->assertDatabaseMissing('notifications', [
            'user_id' => $watcher->id,
            'type' => 'note_edited',
        ]);
    }

    /** @return array{0: Workspace, 1: \App\Models\Note} */
    private function makeNote(User $owner, User $watcher): array
    {
        $tenant = Tenant::create(['slug' => 'notifications-'.uniqid(), 'name' => 'Notifications']);
        $workspace = Workspace::create([
            'tenant_id' => $tenant->id,
            'slug' => 'main-'.uniqid(),
            'name' => 'Main',
            'vault_path' => storage_path('app/vaults/notifications-'.uniqid()),
        ]);
        mkdir($workspace->vault_path, 0755, true);
        $workspace->memberships()->create([
            'tenant_id' => $tenant->id,
            'subject_id' => (string) $watcher->id,
            'user_id' => $watcher->id,
            'role' => 'editor',
        ]);

        /** @var VaultStorage $storage */
        $storage = $this->app->make(VaultStorage::class);
        $note = $storage->write($workspace, 'watched.md', '# Watched');

        return [$workspace, $note];
    }
}
