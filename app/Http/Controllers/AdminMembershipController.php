<?php

namespace App\Http\Controllers;

use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Auth\AuthenticatedSubject;
use App\Models\Membership;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class AdminMembershipController extends Controller
{
    public const ALLOWED_ROLES = ['owner', 'admin', 'editor', 'viewer'];

    public function __construct(
        private readonly AuditRecorder $auditRecorder = new AuditRecorder,
    ) {}

    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorizeAdmin($request);

        $memberships = Membership::query()
            ->where('tenant_id', $workspace->tenant_id)
            ->where(function ($q) use ($workspace) {
                $q->where('workspace_id', $workspace->id)
                    ->orWhereNull('workspace_id');
            })
            ->orderBy('id')
            ->get();

        return response()->json(['data' => $memberships]);
    }

    public function store(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorizeAdmin($request);

        $validated = $request->validate([
            'subject_id' => ['required', 'string', 'max:191'],
            'role' => ['required', 'string', Rule::in(self::ALLOWED_ROLES)],
        ]);

        // Cross-tenant check: ensure workspace belongs to requested tenant context
        $membership = Membership::query()->updateOrCreate(
            [
                'tenant_id' => $workspace->tenant_id,
                'workspace_id' => $workspace->id,
                'subject_id' => $validated['subject_id'],
            ],
            [
                'role' => $validated['role'],
            ]
        );

        $this->auditRecorder->record(
            event: AuditEvent::MEMBERSHIP_GRANTED,
            tenantId: $workspace->tenant_id,
            workspaceId: $workspace->id,
            actorId: $this->currentSubject($request)?->subjectId,
            metadata: [
                'target_subject_id' => $validated['subject_id'],
                'role' => $validated['role'],
            ]
        );

        return response()->json(['data' => $membership], 201);
    }

    public function update(Request $request, Workspace $workspace, Membership $member): JsonResponse
    {
        $this->authorizeAdmin($request);
        $this->ensureWorkspaceScope($workspace, $member);

        $validated = $request->validate([
            'role' => ['required', 'string', Rule::in(self::ALLOWED_ROLES)],
        ]);

        // Last owner protection
        if ($member->role === 'owner' && $validated['role'] !== 'owner') {
            $this->ensureNotLastOwner($workspace->tenant_id, $member->id);
        }

        $member->update(['role' => $validated['role']]);

        $this->auditRecorder->record(
            event: AuditEvent::MEMBERSHIP_UPDATED,
            tenantId: $workspace->tenant_id,
            workspaceId: $workspace->id,
            actorId: $this->currentSubject($request)?->subjectId,
            metadata: [
                'target_subject_id' => $member->subject_id,
                'new_role' => $validated['role'],
            ]
        );

        return response()->json(['data' => $member]);
    }

    public function destroy(Request $request, Workspace $workspace, Membership $member): JsonResponse
    {
        $this->authorizeAdmin($request);
        $this->ensureWorkspaceScope($workspace, $member);

        // Last owner protection
        if ($member->role === 'owner') {
            $this->ensureNotLastOwner($workspace->tenant_id, $member->id);
        }

        $subjectId = $member->subject_id;
        $member->delete();

        $this->auditRecorder->record(
            event: AuditEvent::MEMBERSHIP_REVOKED,
            tenantId: $workspace->tenant_id,
            workspaceId: $workspace->id,
            actorId: $this->currentSubject($request)?->subjectId,
            metadata: [
                'target_subject_id' => $subjectId,
            ]
        );

        return response()->json(status: 204);
    }

    private function authorizeAdmin(Request $request): void
    {
        if (config('jotter.auth_bypass', false)) {
            return;
        }

        $subject = $this->currentSubject($request);
        if (! $subject || ! $subject->isAdmin) {
            abort(403, 'Administrator access required.');
        }
    }

    /**
     * AuthorizeWorkspaceAccess middleware resolves the caller via the app's own
     * IdentityProvider (not Laravel's built-in auth guard — $request->user() is never
     * populated by the GrandpaSSOn cookie-auth path, only by LocalIdentityProvider's
     * password-login flow) and stashes the result here.
     */
    private function currentSubject(Request $request): ?AuthenticatedSubject
    {
        return $request->attributes->get('authenticated_subject');
    }

    private function ensureWorkspaceScope(Workspace $workspace, Membership $member): void
    {
        if ($member->tenant_id !== $workspace->tenant_id) {
            abort(404);
        }
    }

    private function ensureNotLastOwner(int $tenantId, int $excludeMembershipId): void
    {
        $ownerCount = Membership::query()
            ->where('tenant_id', $tenantId)
            ->where('role', 'owner')
            ->where('id', '!=', $excludeMembershipId)
            ->count();

        if ($ownerCount === 0) {
            abort(422, 'Cannot demote or revoke the last owner of a tenant.');
        }
    }
}
