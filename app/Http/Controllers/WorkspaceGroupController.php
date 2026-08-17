<?php

namespace App\Http\Controllers;

use App\Domain\Auth\AuthenticatedSubject;
use App\Domain\Auth\Contracts\IdentityProvider;
use App\Domain\Auth\NoteAccess;
use App\Models\Membership;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class WorkspaceGroupController extends Controller
{
    public function __construct(
        private readonly IdentityProvider $identityProvider,
        private readonly NoteAccess $noteAccess,
    ) {}

    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        $subject = $this->authorizedManager($request, $workspace);
        if ($subject instanceof JsonResponse) {
            return $subject;
        }

        return response()->json(['data' => $workspace->groups()->with('members:id,name,email')->orderBy('name')->get()->map(fn (WorkspaceGroup $group) => $this->serialize($group))->all()]);
    }

    public function store(Request $request, Workspace $workspace): JsonResponse
    {
        $subject = $this->authorizedManager($request, $workspace);
        if ($subject instanceof JsonResponse) {
            return $subject;
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'member_ids' => ['sometimes', 'array'],
            'member_ids.*' => ['integer', 'distinct', 'exists:users,id'],
        ]);
        $members = $this->validatedMembers($workspace, $validated['member_ids'] ?? []);
        $group = $workspace->groups()->create(['name' => $validated['name']]);
        $group->members()->sync($members);

        return response()->json(['data' => $this->serialize($group->load('members:id,name,email'))], 201);
    }

    public function update(Request $request, Workspace $workspace, int $group): JsonResponse
    {
        $subject = $this->authorizedManager($request, $workspace);
        if ($subject instanceof JsonResponse) {
            return $subject;
        }
        $model = $workspace->groups()->findOrFail($group);
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'member_ids' => ['sometimes', 'array'],
            'member_ids.*' => ['integer', 'distinct', 'exists:users,id'],
        ]);
        if (array_key_exists('member_ids', $validated)) {
            $model->members()->sync($this->validatedMembers($workspace, $validated['member_ids']));
        }
        if (array_key_exists('name', $validated)) {
            $model->update(['name' => $validated['name']]);
        }

        return response()->json(['data' => $this->serialize($model->fresh('members:id,name,email'))]);
    }

    public function destroy(Request $request, Workspace $workspace, int $group): JsonResponse
    {
        $subject = $this->authorizedManager($request, $workspace);
        if ($subject instanceof JsonResponse) {
            return $subject;
        }
        $workspace->groups()->findOrFail($group)->delete();

        return response()->json(status: 204);
    }

    /** @return list<int> */
    private function validatedMembers(Workspace $workspace, array $memberIds): array
    {
        $members = User::query()->whereIn('id', $memberIds)->get();
        if ($members->count() !== count(array_unique($memberIds))) {
            throw ValidationException::withMessages(['member_ids' => [__('messages.workspace_group_member_mismatch')]]);
        }

        $allowed = Membership::query()
            ->where('tenant_id', $workspace->tenant_id)
            ->whereIn('subject_id', $members->pluck('id')->map(fn ($id) => (string) $id)->all())
            ->where(function ($query) use ($workspace): void {
                $query->where('workspace_id', $workspace->id)->orWhereNull('workspace_id');
            })
            ->pluck('subject_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if (count(array_diff($memberIds, $allowed)) > 0) {
            throw ValidationException::withMessages(['member_ids' => [__('messages.workspace_group_member_mismatch')]]);
        }

        return array_values(array_unique($memberIds));
    }

    private function authorizedManager(Request $request, Workspace $workspace): AuthenticatedSubject|JsonResponse
    {
        $subject = $request->attributes->get('authenticated_subject')
            ?? $this->identityProvider->resolveIdentity($request);
        if (! $subject) {
            return response()->json(['message' => __('messages.unauthenticated')], 401);
        }
        if (! $this->identityProvider->isAuthorizedForWorkspace($subject, $workspace->id)
            || ! $this->noteAccess->canManage($subject, $workspace->id)) {
            return response()->json(['message' => __('messages.forbidden')], 403);
        }

        return $subject;
    }

    /** @return array<string, mixed> */
    private function serialize(WorkspaceGroup $group): array
    {
        return [
            'id' => $group->id,
            'name' => $group->name,
            'members' => $group->members->map(fn (User $member) => [
                'id' => $member->id,
                'name' => $member->name,
                'email' => $member->email,
            ])->values()->all(),
        ];
    }
}
