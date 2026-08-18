<?php

namespace App\Http\Controllers;

use App\Domain\Auth\Contracts\IdentityProvider;
use App\Domain\Notifications\NotificationEmailPreference;
use App\Domain\Notifications\NotificationType;
use App\Models\NotificationPreference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class NotificationPreferenceController extends Controller
{
    public function __construct(
        private readonly IdentityProvider $identityProvider,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => __('messages.unauthenticated')], 401);
        }

        return response()->json([
            'data' => array_map(fn (NotificationType $type): array => $this->serialize($user->id, $type), NotificationType::cases()),
        ]);
    }

    public function update(Request $request, string $type): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => __('messages.unauthenticated')], 401);
        }

        $notificationType = NotificationType::tryFrom($type);
        if ($notificationType === null) {
            return response()->json([
                'message' => __('messages.notification_type_unsupported'),
                'errors' => ['type' => [__('messages.notification_type_unsupported')]],
            ], 422);
        }

        $validated = $request->validate([
            'mode' => ['required', Rule::in(array_map(static fn (NotificationEmailPreference $mode): string => $mode->value, NotificationEmailPreference::cases()))],
        ]);

        NotificationPreference::query()->updateOrCreate(
            ['user_id' => $user->id, 'type' => $notificationType->value],
            ['mode' => $validated['mode']],
        );

        return response()->json(['data' => $this->serialize($user->id, $notificationType)]);
    }

    /** @return array{type: string, mode: string, explicit: bool} */
    private function serialize(int $userId, NotificationType $type): array
    {
        $preference = NotificationPreference::query()
            ->where('user_id', $userId)
            ->where('type', $type->value)
            ->first();
        $mode = $preference?->mode ?? ($type === NotificationType::MENTION
            ? NotificationEmailPreference::IMMEDIATE
            : NotificationEmailPreference::DIGEST);

        return [
            'type' => $type->value,
            'mode' => $mode->value,
            'explicit' => $preference !== null,
        ];
    }

    private function resolveUser(Request $request): ?\App\Models\User
    {
        return $this->identityProvider->resolveIdentity($request)?->user;
    }
}
