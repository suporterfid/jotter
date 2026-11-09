<?php

namespace App\Http\Controllers;

use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Auth\Contracts\IdentityProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The signed-in person edits their own name (#390, LGPD right to correction).
 * With OIDC or GrandpaSSOn the name comes from the sign-in provider and would
 * be overwritten at the next sign-in, so it is changed there instead.
 */
final class UserProfileController extends Controller
{
    public function __construct(
        private readonly IdentityProvider $identityProvider,
        private readonly AuditRecorder $audit = new AuditRecorder,
    ) {}

    public function update(Request $request): JsonResponse
    {
        // The provider is public (GET /api/auth/config), so this check may come first.
        if (config('jotter.auth_provider', 'local') !== 'local') {
            return response()->json(['message' => __('messages.profile_managed_by_provider')], 409);
        }
        $subject = $this->identityProvider->resolveIdentity($request);
        if ($subject === null || $subject->user === null || $subject->credential !== null) {
            return response()->json(['message' => __('messages.unauthenticated')], 401);
        }

        $validated = $request->validate(['name' => ['required', 'string', 'max:191']]);
        $name = trim(preg_replace('/\s+/u', ' ', $validated['name']) ?? '');
        if ($name === '') {
            return response()->json(['message' => __('validation.required', ['attribute' => 'name']), 'errors' => ['name' => [__('validation.required', ['attribute' => 'name'])]]], 422);
        }

        $previous = (string) $subject->user->name;
        $subject->user->update(['name' => $name]);
        if ($previous !== $name) {
            $this->audit->record(event: AuditEvent::PROFILE_UPDATED, actorId: $subject->subjectId, metadata: ['field' => 'name']);
        }

        return response()->json(['data' => ['name' => $name]]);
    }
}
