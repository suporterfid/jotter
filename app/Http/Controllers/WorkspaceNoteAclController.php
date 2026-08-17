<?php

namespace App\Http\Controllers;

use App\Domain\Auth\Contracts\IdentityProvider;
use App\Domain\Auth\NoteAccess;
use App\Models\Membership;
use App\Models\Note;
use App\Models\NoteAclEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceGroup;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class WorkspaceNoteAclController extends Controller
{
    public function __construct(
        private readonly IdentityProvider $identityProvider,
        private readonly NoteAccess $noteAccess,
        private readonly DatabaseManager $database,
    ) {}

    public function show(Request $request, Workspace $workspace, int $note): JsonResponse
    {
        $subject = $this->subject($request);
        if ($subject === null) {
            return response()->json(['message' => __('messages.unauthenticated')], 401);
        }
        if (! $this->identityProvider->isAuthorizedForWorkspace($subject, $workspace->id)) {
            return response()->json(['message' => __('messages.forbidden')], 403);
        }

        $model = $workspace->notes()->findOrFail($note);
        $this->noteAccess->assertView($subject, $model);

        return response()->json(['data' => $this->payload($subject, $workspace, $model)]);
    }

    public function replace(Request $request, Workspace $workspace, int $note): JsonResponse
    {
        $subject = $this->subject($request);
        if ($subject === null) {
            return response()->json(['message' => __('messages.unauthenticated')], 401);
        }
        if (! $this->identityProvider->isAuthorizedForWorkspace($subject, $workspace->id)) {
            return response()->json(['message' => __('messages.forbidden')], 403);
        }
        if (! $this->noteAccess->canManage($subject, $workspace->id)) {
            return response()->json(['message' => __('messages.forbidden')], 403);
        }

        $model = $workspace->notes()->findOrFail($note);
        $entries = $request->validate([
            'entries' => ['present', 'array'],
            'entries.*.principal_type' => ['required', 'string', 'in:user,group'],
            'entries.*.principal_id' => ['required', 'integer', 'min:1'],
            'entries.*.permission' => ['required', 'string', 'in:view,edit'],
        ])['entries'];

        $this->validateEntries($workspace, $entries);

        $this->database->transaction(function () use ($model, $entries): void {
            $model->aclEntries()->delete();
            foreach ($entries as $entry) {
                NoteAclEntry::create([
                    'note_id' => $model->id,
                    'principal_type' => $entry['principal_type'],
                    'principal_id' => $entry['principal_id'],
                    'permission' => $entry['permission'],
                ]);
            }
        });

        return response()->json(['data' => $this->payload($subject, $workspace, $model->fresh())]);
    }

    /** @param array<int, array{principal_type: string, principal_id: int, permission: string}> $entries */
    private function validateEntries(Workspace $workspace, array $entries): void
    {
        $seen = [];
        foreach ($entries as $entry) {
            $key = implode(':', [$entry['principal_type'], $entry['principal_id']]);
            if (isset($seen[$key])) {
                throw ValidationException::withMessages(['entries' => [__('messages.note_acl_duplicate_entry')]]);
            }
            $seen[$key] = true;

            if ($entry['principal_type'] === 'group') {
                $valid = WorkspaceGroup::query()
                    ->where('workspace_id', $workspace->id)
                    ->whereKey($entry['principal_id'])
                    ->exists();
                if (! $valid) {
                    throw ValidationException::withMessages(['entries' => [__('messages.note_acl_group_workspace_mismatch')]]);
                }
                continue;
            }

            $valid = User::query()->whereKey($entry['principal_id'])->exists()
                && Membership::query()
                    ->where('tenant_id', $workspace->tenant_id)
                    ->where('subject_id', (string) $entry['principal_id'])
                    ->where(function ($scope) use ($workspace): void {
                        $scope->where('workspace_id', $workspace->id)->orWhereNull('workspace_id');
                    })
                    ->exists();
            if (! $valid) {
                throw ValidationException::withMessages(['entries' => [__('messages.note_acl_user_workspace_mismatch')]]);
            }
        }
    }

    /** @return array<string, mixed> */
    private function payload($subject, Workspace $workspace, Note $note): array
    {
        $entries = $note->aclEntries()->orderBy('id')->get()->map(function (NoteAclEntry $entry): array {
            $principal = $entry->principal_type === 'group'
                ? WorkspaceGroup::query()->find($entry->principal_id)
                : User::query()->find($entry->principal_id);

            return [
                'id' => $entry->id,
                'principal_type' => $entry->principal_type,
                'principal_id' => $entry->principal_id,
                'permission' => $entry->permission,
                'principal' => $principal ? [
                    'id' => $principal->id,
                    'name' => $principal->name,
                    'email' => $principal instanceof User ? $principal->email : null,
                ] : null,
            ];
        })->values()->all();

        return [
            'restricted' => $entries !== [],
            'entries' => $entries,
            'can_view' => $this->noteAccess->canView($subject, $note),
            'can_edit' => $this->noteAccess->canEdit($subject, $note),
            'can_manage' => $this->noteAccess->canManage($subject, $workspace->id),
        ];
    }

    private function subject(Request $request): ?\App\Domain\Auth\AuthenticatedSubject
    {
        return $request->attributes->get('authenticated_subject')
            ?? $this->identityProvider->resolveIdentity($request);
    }
}
