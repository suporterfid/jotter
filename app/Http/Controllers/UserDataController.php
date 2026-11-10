<?php

namespace App\Http\Controllers;

use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Auth\AuthenticatedSubject;
use App\Domain\Auth\Contracts\IdentityProvider;
use App\Domain\Users\UserDataExport;
use App\Mail\DeletionRequestedEmail;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * "Your profile → Your data" (LGPD art. 18): the signed-in person downloads
 * what Jotter keeps about them and asks for their account to be deleted. The
 * deletion itself stays with an administrator (Administration → Users →
 * Delete, or `user:delete`), who is e-mailed. Browser sessions only: tokens
 * never reach /api/user/* (EnforceCredentialAudience), and this checks again.
 */
final class UserDataController extends Controller
{
    private const EXPORTS_PER_HOUR = 5;

    public function __construct(
        private readonly IdentityProvider $identityProvider,
        private readonly AuditRecorder $audit = new AuditRecorder,
    ) {}

    public function export(Request $request, UserDataExport $export): JsonResponse
    {
        $subject = $this->subject($request);
        if ($subject === null) {
            return response()->json(['message' => __('messages.unauthenticated')], 401);
        }

        $key = 'user-data-export:'.$subject->user->id;
        if (RateLimiter::tooManyAttempts($key, self::EXPORTS_PER_HOUR)) {
            return response()->json(['message' => __('messages.too_many_attempts')], 429, ['Retry-After' => (string) RateLimiter::availableIn($key)]);
        }
        RateLimiter::hit($key, 3600);

        $data = $export->export($subject->user);
        $this->audit->record(event: AuditEvent::USER_DATA_EXPORTED, actorId: $subject->subjectId, metadata: ['format' => UserDataExport::FORMAT]);

        $filename = 'my-data-'.now()->format('Y-m-d').'.json';

        return response()->json($data, 200, [
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-store',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function requestDeletion(Request $request): JsonResponse
    {
        $subject = $this->subject($request);
        if ($subject === null) {
            return response()->json(['message' => __('messages.unauthenticated')], 401);
        }
        $user = $subject->user;

        if ($user->deletion_requested_at === null) {
            $otherAdmins = User::query()->where('is_admin', true)->where('is_active', true)->whereKeyNot($user->id);
            if ($user->is_admin && $otherAdmins->doesntExist()) {
                return response()->json(['message' => __('messages.user_delete_last_admin')], 409);
            }

            $user->forceFill(['deletion_requested_at' => now()])->save();
            $this->audit->record(event: AuditEvent::USER_DELETION_REQUESTED, actorId: $subject->subjectId, metadata: ['user_id' => $user->id]);

            foreach ($otherAdmins->get() as $administrator) {
                try {
                    Mail::to($administrator->email)->send(new DeletionRequestedEmail($administrator, $user));
                } catch (Throwable $exception) {
                    Log::warning('Deletion-request e-mail failed', ['admin_id' => $administrator->id, 'error' => $exception->getMessage()]);
                }
            }
        }

        return $this->status($user);
    }

    public function cancelDeletion(Request $request): JsonResponse
    {
        $subject = $this->subject($request);
        if ($subject === null) {
            return response()->json(['message' => __('messages.unauthenticated')], 401);
        }
        $user = $subject->user;

        if ($user->deletion_requested_at !== null) {
            $user->forceFill(['deletion_requested_at' => null])->save();
            $this->audit->record(event: AuditEvent::USER_DELETION_REQUEST_CANCELLED, actorId: $subject->subjectId, metadata: ['user_id' => $user->id]);
        }

        return $this->status($user);
    }

    /**
     * @phpstan-assert-if-true !null $subject->user
     */
    private function subject(Request $request): ?AuthenticatedSubject
    {
        $subject = $this->identityProvider->resolveIdentity($request);

        return $subject !== null && $subject->user !== null && ! $subject->isCredential() ? $subject : null;
    }

    private function status(User $user): JsonResponse
    {
        return response()->json(['data' => ['deletion_requested_at' => $user->deletion_requested_at?->toIso8601String()]]);
    }
}
