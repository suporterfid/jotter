<?php

namespace App\Domain\Vault\Store\GitHub;

use App\Domain\Notifications\NotificationEmailService;
use App\Domain\Notifications\NotificationType;
use App\Models\Identity;
use App\Models\Membership;
use App\Models\Note;
use App\Models\NoteWatcher;
use App\Models\Notification;
use App\Models\TenantVaultStore;
use App\Models\User;

/**
 * In-app notifications (type `vault_sync`) for what the GitHub sync did on its
 * own: a conflict copy, an external edit that won over a Jotter delete, a
 * Jotter edit that won over an external delete (plan §5.6), and a store that
 * stopped working (§6.5). Email follows the user's digest preference.
 */
class GitHubSyncNotifier
{
    /**
     * Watchers of the note and the Jotter user who made the local edit.
     *
     * @param  array<string, mixed>  $data
     */
    public function aboutNote(Note $note, ?string $editorSubjectId, string $kind, string $title, array $data = []): int
    {
        $userIds = NoteWatcher::query()->where('note_id', $note->id)->where('is_watching', true)->pluck('user_id')->map(fn ($id): int => (int) $id)->all();
        $editor = $this->userForSubject($editorSubjectId);
        if ($editor !== null) {
            $userIds[] = $editor->id;
        }

        $payload = $data + [
            'kind' => $kind,
            'note_id' => $note->id,
            'note_path' => $note->path,
            'note_title' => $note->title,
            'target_kind' => 'note',
            'actor_name' => 'GitHub',
        ];

        return $this->send(array_values(array_unique($userIds)), (int) $note->workspace_id, $title, $payload);
    }

    /**
     * Tenant owners and admins.
     *
     * @param  array<string, mixed>  $data
     */
    public function aboutStore(TenantVaultStore $store, ?int $workspaceId, string $kind, string $title, array $data = []): int
    {
        if ($workspaceId === null) {
            // Notifications belong to a workspace; a store nobody uses only shows in the doctor.
            return 0;
        }
        $subjects = Membership::query()->where('tenant_id', $store->tenant_id)->whereNull('workspace_id')
            ->whereIn('role', ['owner', 'admin'])->pluck('subject_id')->map(fn ($id): string => (string) $id)->all();

        $userIds = [];
        foreach ($subjects as $subject) {
            $user = $this->userForSubject($subject);
            if ($user !== null) {
                $userIds[] = $user->id;
            }
        }

        return $this->send(array_values(array_unique($userIds)), $workspaceId, $title, $data + [
            'kind' => $kind,
            'vault_store_id' => $store->id,
            'target_kind' => 'storage',
            'actor_name' => 'GitHub',
        ]);
    }

    /**
     * @param  list<int>  $userIds
     * @param  array<string, mixed>  $data
     */
    private function send(array $userIds, int $workspaceId, string $title, array $data): int
    {
        $sent = 0;
        foreach ($userIds as $userId) {
            $notification = Notification::query()->create([
                'workspace_id' => $workspaceId,
                'user_id' => $userId,
                'type' => NotificationType::VAULT_SYNC->value,
                'title' => mb_substr($title, 0, 250),
                'data' => $data,
            ]);
            try {
                app(NotificationEmailService::class)->enqueueImmediate($notification);
            } catch (\Throwable) {
                // Email is best effort; the in-app notification exists.
            }
            $sent++;
        }

        return $sent;
    }

    private function userForSubject(?string $subject): ?User
    {
        if ($subject === null || $subject === '' || str_starts_with($subject, 'github')) {
            return null;
        }
        if (ctype_digit($subject)) {
            $user = User::query()->find((int) $subject);
            if ($user !== null) {
                return $user;
            }
        }
        $user = User::query()->where('email', $subject)->first();
        if ($user !== null) {
            return $user;
        }
        $userId = Identity::query()->where('subject_id', $subject)->value('user_id');

        return $userId === null ? null : User::query()->find($userId);
    }
}
