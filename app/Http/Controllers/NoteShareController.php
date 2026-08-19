<?php

namespace App\Http\Controllers;

use App\Domain\Auth\AuthenticatedSubject;
use App\Domain\Auth\Contracts\IdentityProvider;
use App\Domain\Sharing\NoteShareService;
use App\Models\Note;
use App\Models\NoteShare;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

final class NoteShareController extends Controller
{
    public function __construct(
        private readonly IdentityProvider $identityProvider,
        private readonly NoteShareService $noteShareService,
    ) {}

    public function show(Request $request, Workspace $workspace, int $note): JsonResponse
    {
        [$subject, $model] = $this->authorizeManagement($request, $workspace, $note);
        $share = $model->shares()->active()->latest('id')->first();

        return response()->json(['data' => $this->payload($share)]);
    }

    public function store(Request $request, Workspace $workspace, int $note): JsonResponse
    {
        [$subject, $model] = $this->authorizeManagement($request, $workspace, $note);
        $validated = $request->validate([
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);

        $result = $this->noteShareService->create(
            $model,
            $subject,
            isset($validated['expires_at']) ? Carbon::parse($validated['expires_at']) : null,
        );

        $payload = $this->payload($result['share']);
        $payload['token'] = $result['token'];
        $payload['url'] = url('/share/'.$result['token']);

        return response()->json(['data' => $payload], 201);
    }

    public function destroy(Request $request, Workspace $workspace, int $note): JsonResponse
    {
        [$subject, $model] = $this->authorizeManagement($request, $workspace, $note);
        $share = $model->shares()->active()->latest('id')->first();

        if ($share !== null) {
            $share = $this->noteShareService->revoke($share, $subject);
        }

        return response()->json(['data' => $this->payload($share)]);
    }

    /** @return array{0: AuthenticatedSubject, 1: Note} */
    private function authorizeManagement(Request $request, Workspace $workspace, int $note): array
    {
        $subject = $this->subject($request);
        if ($subject === null) {
            abort(response()->json(['message' => __('messages.unauthenticated')], 401));
        }
        if (! $this->identityProvider->isAuthorizedForWorkspace($subject, $workspace->id)) {
            abort(response()->json(['message' => __('messages.forbidden')], 403));
        }

        $model = $workspace->notes()->findOrFail($note);
        $this->noteShareService->assertCanManage($model, $subject);

        return [$subject, $model];
    }

    /** @return array{active: bool, url: null, expires_at: ?string, revoked_at: ?string} */
    private function payload(?NoteShare $share): array
    {
        return [
            'active' => $share?->isActive() ?? false,
            'url' => null,
            'expires_at' => $share?->expires_at?->toIso8601String(),
            'revoked_at' => $share?->revoked_at?->toIso8601String(),
        ];
    }

    private function subject(Request $request): ?AuthenticatedSubject
    {
        return $request->attributes->get('authenticated_subject')
            ?? $this->identityProvider->resolveIdentity($request);
    }
}
