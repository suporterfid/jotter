<?php

namespace App\Domain\Notifications;

use App\Domain\Jobs\Contracts\JobDispatcher;
use App\Mail\NotificationDigestEmail;
use App\Mail\NotificationEmail;
use App\Jobs\SendNotificationEmail;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\NotificationPreference;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

final class NotificationEmailService
{
    public function __construct(
        private readonly JobDispatcher $dispatcher,
    ) {}

    public function enqueueImmediate(Notification $notification): ?NotificationDelivery
    {
        if ($this->preferenceFor($notification) !== NotificationEmailPreference::IMMEDIATE) {
            return null;
        }

        $dedupeKey = 'notification:'.$notification->id.':email';
        $delivery = NotificationDelivery::query()->firstOrCreate(
            ['dedupe_key' => $dedupeKey],
            [
                'user_id' => $notification->user_id,
                'notification_id' => $notification->id,
                'channel' => 'email',
                'kind' => 'immediate',
                'status' => 'pending',
                'payload' => ['notification_id' => $notification->id],
            ],
        );

        if ($delivery->wasRecentlyCreated) {
            $this->dispatcher->dispatch(
                SendNotificationEmail::class,
                ['delivery_id' => $delivery->id],
                $notification->workspace_id,
            );
            $delivery->update(['dispatched_at' => now()]);
        }

        return $delivery;
    }

    public function sendDelivery(NotificationDelivery $delivery): void
    {
        if ($delivery->status === 'sent') {
            return;
        }

        $delivery->update([
            'status' => 'claimed',
            'claimed_at' => now(),
            'error' => null,
        ]);

        if (config('mail.default') === 'log') {
            Log::info('notification_email_skipped', [
                'delivery_id' => $delivery->id,
                'reason' => 'mail_driver_log',
            ]);
            $delivery->update(['status' => 'sent', 'sent_at' => now()]);
            return;
        }

        try {
            $recipient = $delivery->user()->firstOrFail();

            if ($delivery->kind === 'digest') {
                $notifications = $delivery->items()
                    ->with('notification')
                    ->get()
                    ->pluck('notification')
                    ->filter()
                    ->values()
                    ->all();
                Mail::to($recipient->email)->send(new NotificationDigestEmail($recipient, $notifications));
            } else {
                $notification = $delivery->notification()->firstOrFail();
                Mail::to($recipient->email)->send(new NotificationEmail($recipient, $notification));
            }

            $delivery->update(['status' => 'sent', 'sent_at' => now()]);
        } catch (\Throwable $exception) {
            $delivery->update([
                'status' => 'failed',
                'failed_at' => now(),
                'error' => $exception->getMessage(),
            ]);
            Log::error('notification_email_failed', [
                'delivery_id' => $delivery->id,
                'exception' => $exception,
            ]);
        }
    }

    public function preferenceFor(Notification $notification): NotificationEmailPreference
    {
        $mode = NotificationPreference::query()
            ->where('user_id', $notification->user_id)
            ->where('type', $notification->type)
            ->value('mode');

        if ($mode !== null) {
            return $mode instanceof NotificationEmailPreference
                ? $mode
                : NotificationEmailPreference::from((string) $mode);
        }

        return $notification->type === NotificationType::MENTION->value
            ? NotificationEmailPreference::IMMEDIATE
            : NotificationEmailPreference::DIGEST;
    }
}
