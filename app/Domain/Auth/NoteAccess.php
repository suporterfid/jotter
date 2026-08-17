<?php

namespace App\Domain\Auth;

use App\Models\Membership;
use App\Models\Note;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class NoteAccess
{
    public function canView(AuthenticatedSubject $subject, Note $note): bool
    {
        if (! $this->hasWorkspaceAccess($subject, $note->workspace_id)) {
            return false;
        }

        if ($this->bypassesRestrictions($subject, $note->workspace_id)) {
            return true;
        }

        if (! $this->isRestricted($note)) {
            return true;
        }

        if ($this->isServiceToken($subject) || $subject->user === null) {
            return false;
        }

        return $this->matchingGrantExists($subject, $note, 'view');
    }

    public function canEdit(AuthenticatedSubject $subject, Note $note): bool
    {
        if (! $this->canWriteWorkspace($subject, $note->workspace_id)) {
            return false;
        }

        if ($this->bypassesRestrictions($subject, $note->workspace_id)) {
            return true;
        }

        if ($this->isServiceToken($subject) || $subject->user === null) {
            return false;
        }

        if (! $this->isRestricted($note)) {
            return true;
        }

        return $this->matchingGrantExists($subject, $note, 'edit');
    }

    public function assertView(AuthenticatedSubject $subject, Note $note): void
    {
        if ($this->canView($subject, $note)) {
            return;
        }

        throw (new ModelNotFoundException())->setModel(Note::class, [$note->id]);
    }

    public function assertEdit(AuthenticatedSubject $subject, Note $note): void
    {
        if ($this->canEdit($subject, $note)) {
            return;
        }

        throw new AuthorizationException(__('messages.forbidden'));
    }

    public function scopeVisible(Builder $query, AuthenticatedSubject $subject, int $workspaceId): Builder
    {
        $query->where($query->getModel()->getTable().'.workspace_id', $workspaceId);

        if (! $this->hasWorkspaceAccess($subject, $workspaceId)) {
            return $query->whereRaw('1 = 0');
        }

        if ($this->bypassesRestrictions($subject, $workspaceId)) {
            return $query;
        }

        $this->applyVisibilityPredicate($query, $subject);

        return $query;
    }

    public function scopeEditable(Builder $query, AuthenticatedSubject $subject, int $workspaceId): Builder
    {
        $query->where($query->getModel()->getTable().'.workspace_id', $workspaceId);

        if (! $this->canWriteWorkspace($subject, $workspaceId)) {
            return $query->whereRaw('1 = 0');
        }

        if ($this->bypassesRestrictions($subject, $workspaceId)) {
            return $query;
        }

        $this->applyVisibilityPredicate($query, $subject, true);

        return $query;
    }

    public function isRestricted(Note $note): bool
    {
        return $note->aclEntries()->exists();
    }

    private function matchingGrantExists(AuthenticatedSubject $subject, Note $note, string $permission): bool
    {
        $userId = $subject->user?->id;
        if ($userId === null) {
            return false;
        }

        return $note->aclEntries()
            ->where('permission', $permission)
            ->where(function ($query) use ($userId, $note): void {
                $query->where(function ($userQuery) use ($userId): void {
                    $userQuery->where('principal_type', 'user')
                        ->where('principal_id', $userId);
                })->orWhere(function ($groupQuery) use ($userId, $note): void {
                    $groupQuery->where('principal_type', 'group')
                        ->whereExists(function ($group) use ($userId, $note): void {
                            $group->selectRaw('1')
                                ->from('workspace_groups')
                                ->join('workspace_group_members', 'workspace_group_members.workspace_group_id', '=', 'workspace_groups.id')
                                ->whereColumn('workspace_groups.id', 'note_acl_entries.principal_id')
                                ->where('workspace_groups.workspace_id', $note->workspace_id)
                                ->where('workspace_group_members.user_id', $userId);
                        });
                });
            })
            ->exists()
            || ($permission === 'view' && $note->aclEntries()->where('permission', 'edit')->where(function ($query) use ($userId, $note): void {
                $query->where(function ($userQuery) use ($userId): void {
                    $userQuery->where('principal_type', 'user')->where('principal_id', $userId);
                })->orWhere(function ($groupQuery) use ($userId, $note): void {
                    $groupQuery->where('principal_type', 'group')->whereExists(function ($group) use ($userId, $note): void {
                        $group->selectRaw('1')
                            ->from('workspace_groups')
                            ->join('workspace_group_members', 'workspace_group_members.workspace_group_id', '=', 'workspace_groups.id')
                            ->whereColumn('workspace_groups.id', 'note_acl_entries.principal_id')
                            ->where('workspace_groups.workspace_id', $note->workspace_id)
                            ->where('workspace_group_members.user_id', $userId);
                    });
                });
            })->exists());
    }

    private function applyVisibilityPredicate(Builder $query, AuthenticatedSubject $subject, bool $editable = false): void
    {
        $query->where(function (Builder $visible) use ($subject, $editable): void {
            $visible->whereNotExists(function ($acl): void {
                $acl->selectRaw('1')
                    ->from('note_acl_entries')
                    ->whereColumn('note_acl_entries.note_id', 'notes.id');
            });

            if ($this->isServiceToken($subject) || $subject->user === null) {
                return;
            }

            $permission = $editable ? 'edit' : 'view';
            $userId = $subject->user->id;
            $visible->orWhereExists(function ($acl) use ($permission, $userId): void {
                $acl->selectRaw('1')
                    ->from('note_acl_entries')
                    ->whereColumn('note_acl_entries.note_id', 'notes.id')
                    ->where('note_acl_entries.principal_type', 'user')
                    ->where('note_acl_entries.principal_id', $userId)
                    ->where(function ($grant) use ($permission): void {
                        $grant->where('note_acl_entries.permission', $permission);
                        if ($permission === 'view') {
                            $grant->orWhere('note_acl_entries.permission', 'edit');
                        }
                    });
            });
            $visible->orWhereExists(function ($acl) use ($permission, $userId): void {
                $acl->selectRaw('1')
                    ->from('note_acl_entries')
                    ->join('workspace_groups', function ($join): void {
                        $join->on('workspace_groups.id', '=', 'note_acl_entries.principal_id')
                            ->where('note_acl_entries.principal_type', 'group');
                    })
                    ->join('workspace_group_members', 'workspace_group_members.workspace_group_id', '=', 'workspace_groups.id')
                    ->whereColumn('note_acl_entries.note_id', 'notes.id')
                    ->whereColumn('workspace_groups.workspace_id', 'notes.workspace_id')
                    ->where('workspace_group_members.user_id', $userId)
                    ->where(function ($grant) use ($permission): void {
                        $grant->where('note_acl_entries.permission', $permission);
                        if ($permission === 'view') {
                            $grant->orWhere('note_acl_entries.permission', 'edit');
                        }
                    });
            });
        });
    }

    private function hasWorkspaceAccess(AuthenticatedSubject $subject, int $workspaceId): bool
    {
        if ($subject->isAdmin || $this->isServiceToken($subject)) {
            return $this->isServiceToken($subject)
                ? in_array($workspaceId, $this->serviceWorkspaceIds($subject), true)
                : true;
        }

        $workspace = Workspace::query()->find($workspaceId);
        if ($workspace === null || $subject->user === null) {
            return false;
        }

        return Membership::query()
            ->where('tenant_id', $workspace->tenant_id)
            ->whereIn('subject_id', $this->subjectIds($subject))
            ->where(function ($query) use ($workspaceId): void {
                $query->where('workspace_id', $workspaceId)->orWhereNull('workspace_id');
            })
            ->exists();
    }

    private function canWriteWorkspace(AuthenticatedSubject $subject, int $workspaceId): bool
    {
        if ($subject->isAdmin) {
            return true;
        }

        if ($this->isServiceToken($subject) || ! $this->hasWorkspaceAccess($subject, $workspaceId)) {
            return false;
        }

        $workspace = Workspace::query()->find($workspaceId);
        if ($workspace === null) {
            return false;
        }

        return Membership::query()
            ->where('tenant_id', $workspace->tenant_id)
            ->whereIn('subject_id', $this->subjectIds($subject))
            ->where(function ($query) use ($workspaceId): void {
                $query->where('workspace_id', $workspaceId)->orWhereNull('workspace_id');
            })
            ->whereIn('role', ['owner', 'admin', 'editor'])
            ->exists();
    }

    private function bypassesRestrictions(AuthenticatedSubject $subject, int $workspaceId): bool
    {
        if ($subject->isAdmin) {
            return true;
        }

        if ($this->isServiceToken($subject) || $subject->user === null) {
            return false;
        }

        $workspace = Workspace::query()->find($workspaceId);
        if ($workspace === null) {
            return false;
        }

        return Membership::query()
            ->where('tenant_id', $workspace->tenant_id)
            ->whereIn('subject_id', $this->subjectIds($subject))
            ->where(function ($query) use ($workspaceId): void {
                $query->where('workspace_id', $workspaceId)->orWhereNull('workspace_id');
            })
            ->whereIn('role', ['owner', 'admin'])
            ->exists();
    }

    /** @return list<string> */
    private function subjectIds(AuthenticatedSubject $subject): array
    {
        $ids = array_filter([$subject->subjectId, (string) $subject->user?->id, $subject->email]);

        if ($subject->user !== null) {
            $ids = array_merge($ids, $subject->user->identities()->pluck('subject_id')->all());
        }

        return array_values(array_unique($ids));
    }

    private function isServiceToken(AuthenticatedSubject $subject): bool
    {
        return ($subject->attributes['auth_method'] ?? null) === 'grandpasson_service_token';
    }

    /** @return list<int> */
    private function serviceWorkspaceIds(AuthenticatedSubject $subject): array
    {
        $ids = [];
        foreach ($subject->attributes['audiences'] ?? [] as $audience) {
            if (preg_match('/^workspace\/(\d+)$/', (string) $audience, $matches)) {
                $ids[] = (int) $matches[1];
            }
        }

        return $ids;
    }
}
