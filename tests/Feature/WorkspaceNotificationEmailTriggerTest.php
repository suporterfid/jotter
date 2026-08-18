<?php

namespace Tests\Feature;

use App\Domain\Auth\AuthenticatedSubject;
use App\Domain\Events\WorkspaceEventEmitter;
use App\Domain\Notifications\NotificationEmailPreference;
use App\Domain\Notifications\NotificationType;
use App\Domain\Vault\VaultStorage;
use App\Models\NoteComment;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\NotificationPreference;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class WorkspaceNotificationEmailTriggerTest extends TestCase
{
    use RefreshDatabase;

    public function test_immediate_email_delivery_is_created_for_mentions_and_every_cf5_event(): void
    {
        $owner = User::factory()->create(['is_admin' => true]);
        $watcher = User::factory()->create();
        [$workspace, $note] = $this->makeNote($owner, $watcher);
        $types = [
            NotificationType::MENTION,
            NotificationType::NOTE_COMMENTED,
            NotificationType::COMMENT_REPLY,
            NotificationType::NOTE_EDITED,
            NotificationType::NOTE_MOVED,
            NotificationType::NOTE_DELETED,
        ];

        foreach ($types as $type) {
            NotificationPreference::create([
                'user_id' => $watcher->id,
                'type' => $type->value,
                'mode' => NotificationEmailPreference::IMMEDIATE,
            ]);
        }

        $emitter = app(WorkspaceEventEmitter::class);
        $emitter->watch($note, $watcher->id);
        $actor = new AuthenticatedSubject(
            subjectId: (string) $owner->id,
            email: $owner->email,
            name: $owner->name,
            isAdmin: true,
            user: $owner,
        );

        $emitter->emitMention($workspace->id, (string) $owner->id, $watcher->id, $note->title, 'Please review');
        $emitter->emitNoteEdited($note, $actor);
        $emitter->emitNoteMoved($note, $actor, 'old.md');
        $emitter->emitNoteDeleted($note, $actor);

        $comment = NoteComment::create([
            'workspace_id' => $workspace->id,
            'note_id' => $note->id,
            'user_id' => $owner->id,
            'actor_name' => $owner->name,
            'content' => 'A comment',
        ]);
        $emitter->emitCommentAdded($note, $comment, $actor);

        $reply = NoteComment::create([
            'workspace_id' => $workspace->id,
            'note_id' => $note->id,
            'parent_comment_id' => $comment->id,
            'user_id' => $owner->id,
            'actor_name' => $owner->name,
            'content' => 'A reply',
        ]);
        $emitter->emitCommentReply($note, $reply, $comment, $actor);

        $this->assertSame(count($types), Notification::query()
            ->where('user_id', $watcher->id)
            ->whereIn('type', array_map(fn (NotificationType $type): string => $type->value, $types))
            ->count());
        $this->assertSame(count($types), NotificationDelivery::query()
            ->where('user_id', $watcher->id)
            ->where('channel', 'email')
            ->count());
    }

    /** @return array{0: Workspace, 1: \App\Models\Note} */
    private function makeNote(User $owner, User $watcher): array
    {
        $tenant = Tenant::create(['slug' => 'email-events-'.uniqid(), 'name' => 'Email events']);
        $workspace = Workspace::create([
            'tenant_id' => $tenant->id,
            'slug' => 'email-events-'.uniqid(),
            'name' => 'Email events',
            'vault_path' => storage_path('app/vaults/email-events-'.uniqid()),
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
