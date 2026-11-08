<?php

namespace App\Http\Controllers;

use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Auth\AuthenticatedSubject;
use App\Mail\SignupApprovedEmail;
use App\Mail\SignupRejectedEmail;
use App\Models\Membership;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Administrators approve or reject sign-up requests (#390). Approval turns the
 * account on and, when JOTTER_SIGNUP_DEFAULT_WORKSPACE is set, adds a
 * membership there. Rejection deletes the request (the person's data is not
 * kept) after telling them.
 */
final class AdminSignupController extends Controller
{
    public function __construct(private readonly AuditRecorder $audit = new AuditRecorder) {}

    public function approve(Request $request, User $user): JsonResponse
    {
        $actor = $this->authorizeAdmin($request);
        if ($user->signup_status !== 'pending') {
            return response()->json(['message' => __('messages.signup_not_pending')], 422);
        }

        $user->forceFill(['is_active' => true, 'signup_status' => 'approved'])->save();

        $workspaceSlug = (string) config('jotter.signup.default_workspace', '');
        $workspace = $workspaceSlug === '' ? null : Workspace::query()->where('slug', $workspaceSlug)->first();
        if ($workspace !== null) {
            $role = in_array(config('jotter.signup.default_role'), ['viewer', 'editor'], true) ? (string) config('jotter.signup.default_role') : 'viewer';
            Membership::query()->firstOrCreate(
                ['tenant_id' => $workspace->tenant_id, 'workspace_id' => $workspace->id, 'subject_id' => (string) $user->id],
                ['role' => $role],
            );
        }

        $this->audit->record(event: AuditEvent::SIGNUP_APPROVED, actorId: $actor?->subjectId, metadata: ['user_id' => $user->id, 'workspace' => $workspace?->slug]);
        $this->notify($user, new SignupApprovedEmail($user));

        return response()->json(['data' => ['id' => $user->id, 'is_active' => true, 'signup_status' => 'approved', 'workspace' => $workspace?->slug]]);
    }

    public function reject(Request $request, User $user): JsonResponse
    {
        $actor = $this->authorizeAdmin($request);
        if ($user->signup_status !== 'pending') {
            return response()->json(['message' => __('messages.signup_not_pending')], 422);
        }

        $this->notify($user, new SignupRejectedEmail($user));
        $this->audit->record(event: AuditEvent::SIGNUP_REJECTED, actorId: $actor?->subjectId, metadata: ['user_id' => $user->id]);
        $user->delete();

        return response()->json(['data' => ['id' => $user->id, 'deleted' => true]]);
    }

    private function notify(User $user, \Illuminate\Mail\Mailable $mail): void
    {
        try {
            Mail::to($user->email)->send($mail);
        } catch (Throwable $exception) {
            Log::warning('Sign-up decision e-mail failed', ['user_id' => $user->id, 'error' => $exception->getMessage()]);
        }
    }

    private function authorizeAdmin(Request $request): ?AuthenticatedSubject
    {
        $subject = $request->attributes->get('authenticated_subject');
        if (! config('jotter.auth_bypass', false) && (! $subject instanceof AuthenticatedSubject || ! $subject->isAdmin)) {
            abort(403, 'Administrator access required.');
        }

        return $subject instanceof AuthenticatedSubject ? $subject : null;
    }
}
