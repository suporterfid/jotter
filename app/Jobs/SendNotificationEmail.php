<?php

namespace App\Jobs;

use App\Domain\Notifications\NotificationEmailService;
use App\Models\NotificationDelivery;

final class SendNotificationEmail
{
    public function __construct(
        private readonly NotificationEmailService $service,
    ) {}

    public function handle(int $deliveryId): void
    {
        $delivery = NotificationDelivery::query()->findOrFail($deliveryId);
        $this->service->sendDelivery($delivery);
    }
}
