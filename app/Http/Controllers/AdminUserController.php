<?php

namespace App\Http\Controllers;

use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Auth\AuthenticatedSubject;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

final class AdminUserController extends Controller
{
    public function __construct(
        private readonly AuditRecorder $auditRecorder = new AuditRecorder,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $users = User::query()
            ->select(['id', 'name', 'email', 'is_admin', 'is_active', 'created_at'])
            ->orderBy('id')
            ->get();

        return response()->json(['data' => $users]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $this->ensureLocalProvider();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'email' => ['required', 'string', 'email', 'max:191', 'unique:users,email'],
            'password' => ['required', 'string', Password::default()],
            'is_admin' => ['sometimes', 'boolean'],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'is_admin' => $validated['is_admin'] ?? false,
            'is_active' => true,
        ]);

        $this->auditRecorder->record(
            event: AuditEvent::USER_CREATED,
            actorId: $this->currentSubject($request)?->subjectId,
            metadata: [
                'created_user_id' => $user->id,
                'email' => $user->email,
                'is_admin' => $user->is_admin,
            ]
        );

        return response()->json([
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_admin' => $user->is_admin,
                'is_active' => $user->is_active,
            ],
        ], 201);
    }

    public function deactivate(Request $request, User $user): JsonResponse
    {
        $this->authorizeAdmin($request);
        $this->ensureLocalProvider();

        $user->update(['is_active' => false]);

        $this->auditRecorder->record(
            event: AuditEvent::USER_DEACTIVATED,
            actorId: $this->currentSubject($request)?->subjectId,
            metadata: [
                'target_user_id' => $user->id,
                'email' => $user->email,
            ]
        );

        return response()->json(['message' => 'User deactivated successfully. Active sessions invalidated.']);
    }

    public function reactivate(Request $request, User $user): JsonResponse
    {
        $this->authorizeAdmin($request);
        $this->ensureLocalProvider();

        $user->update(['is_active' => true]);

        $this->auditRecorder->record(
            event: AuditEvent::USER_REACTIVATED,
            actorId: $this->currentSubject($request)?->subjectId,
            metadata: [
                'target_user_id' => $user->id,
                'email' => $user->email,
            ]
        );

        return response()->json(['message' => 'User reactivated successfully.']);
    }

    public function resetPassword(Request $request, User $user): JsonResponse
    {
        $this->authorizeAdmin($request);
        $this->ensureLocalProvider();

        $validated = $request->validate([
            'new_password' => ['required', 'string', Password::default()],
        ]);

        $user->update([
            'password' => Hash::make($validated['new_password']),
        ]);

        $this->auditRecorder->record(
            event: AuditEvent::USER_PASSWORD_RESET,
            actorId: $this->currentSubject($request)?->subjectId,
            metadata: [
                'target_user_id' => $user->id,
                'email' => $user->email,
                'mode' => 'admin_reset',
            ]
        );

        return response()->json(['message' => 'User password reset successfully.']);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            abort(401, 'Unauthenticated.');
        }
        $this->ensureLocalProvider();

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', Password::default()],
        ]);

        if (! Hash::check($validated['current_password'], $user->password)) {
            abort(422, 'Current password does not match.');
        }

        $user->update([
            'password' => Hash::make($validated['new_password']),
        ]);

        $this->auditRecorder->record(
            event: AuditEvent::USER_PASSWORD_RESET,
            actorId: (string) $user->id,
            metadata: [
                'target_user_id' => $user->id,
                'email' => $user->email,
                'mode' => 'self_service',
            ]
        );

        return response()->json(['message' => 'Password changed successfully.']);
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

    private function ensureLocalProvider(): void
    {
        $provider = config('jotter.auth_provider', 'local');
        if ($provider !== 'local') {
            abort(400, 'User management is managed by external identity provider.');
        }
    }
}
