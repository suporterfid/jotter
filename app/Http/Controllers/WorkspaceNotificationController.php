<?php

namespace App\Http\Controllers;

use App\Domain\Auth\Contracts\IdentityProvider;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WorkspaceNotificationController extends Controller
{
    public function __construct(
        private readonly IdentityProvider $identityProvider
    ) {}

    public function index(Request $request, int $workspaceId): JsonResponse
    {
        $subject = $this->identityProvider->resolveIdentity($request);
        if (! $subject) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (! $this->identityProvider->isAuthorizedForWorkspace($subject, $workspaceId)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if (! $subject->user) {
            return response()->json(['data' => []]);
        }

        $notifications = Notification::query()
            ->where('workspace_id', $workspaceId)
            ->where('user_id', $subject->user->id)
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => $notifications]);
    }

    public function markAsRead(Request $request, int $workspaceId, int $notificationId): JsonResponse
    {
        $subject = $this->identityProvider->resolveIdentity($request);
        if (! $subject) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (! $this->identityProvider->isAuthorizedForWorkspace($subject, $workspaceId)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $notification = Notification::query()
            ->where('workspace_id', $workspaceId)
            ->where('user_id', $subject->user?->id)
            ->where('id', $notificationId)
            ->first();

        if (! $notification) {
            return response()->json(['message' => 'Notification not found.'], 404);
        }

        $notification->update(['read_at' => now()]);

        return response()->json(['data' => $notification]);
    }

    public function destroy(Request $request, int $workspaceId, int $notificationId): JsonResponse
    {
        $subject = $this->identityProvider->resolveIdentity($request);
        if (! $subject) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (! $this->identityProvider->isAuthorizedForWorkspace($subject, $workspaceId)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $notification = Notification::query()
            ->where('workspace_id', $workspaceId)
            ->where('user_id', $subject->user?->id)
            ->where('id', $notificationId)
            ->first();

        if (! $notification) {
            return response()->json(['message' => 'Notification not found.'], 404);
        }

        $notification->delete();

        return response()->json(['message' => 'Notification deleted.']);
    }
}
