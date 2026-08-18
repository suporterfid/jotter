<?php

namespace App\Console\Commands;

use App\Domain\Jobs\Contracts\JobDispatcher;
use App\Domain\Notifications\NotificationEmailPreference;
use App\Domain\Notifications\NotificationEmailService;
use App\Jobs\SendNotificationEmail;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\NotificationDeliveryItem;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

final class SendNotificationDigestCommand extends Command
{
    protected $signature = 'notifications:send-digest {--limit=100 : Maximum notifications per recipient in this run}';

    protected $description = 'Dispatch bounded email notification digests';

    public function __construct(
        private readonly JobDispatcher $dispatcher,
        private readonly NotificationEmailService $emailService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $window = Carbon::now('UTC')->startOfMinute();
        $candidateLimit = min(10000, $limit * 100);
        $grouped = [];

        $notifications = Notification::query()
            ->with('user')
            ->whereDoesntHave('deliveryItems', fn ($query) => $query->where('channel', 'email'))
            ->orderBy('id')
            ->limit($candidateLimit)
            ->get();

        foreach ($notifications as $notification) {
            if ($notification->user === null
                || $this->emailService->preferenceFor($notification) !== NotificationEmailPreference::DIGEST) {
                continue;
            }

            $grouped[$notification->user_id] ??= [];
            if (count($grouped[$notification->user_id]) < $limit) {
                $grouped[$notification->user_id][] = $notification;
            }
        }

        $deliveryCount = 0;
        $itemCount = 0;
        foreach ($grouped as $userId => $items) {
            $dedupeKey = 'digest:'.$userId.':'.$window->format('Y-m-d\TH:i:00\Z');
            $delivery = NotificationDelivery::query()->firstOrCreate(
                ['dedupe_key' => $dedupeKey],
                [
                    'user_id' => $userId,
                    'channel' => 'email',
                    'kind' => 'digest',
                    'status' => 'pending',
                    'payload' => ['window' => $window->toIso8601String()],
                ],
            );

            foreach ($items as $notification) {
                $item = NotificationDeliveryItem::query()->firstOrCreate([
                    'delivery_id' => $delivery->id,
                    'notification_id' => $notification->id,
                    'channel' => 'email',
                ]);
                $itemCount += $item->wasRecentlyCreated ? 1 : 0;
            }

            if ($delivery->dispatched_at === null && $delivery->status !== 'sent') {
                $this->dispatcher->dispatch(
                    SendNotificationEmail::class,
                    ['delivery_id' => $delivery->id],
                    null,
                );
                $delivery->update(['dispatched_at' => now()]);
                $deliveryCount++;
            }
        }

        $this->info("Dispatched {$deliveryCount} digest(s) containing {$itemCount} notification(s).");

        return self::SUCCESS;
    }
}
