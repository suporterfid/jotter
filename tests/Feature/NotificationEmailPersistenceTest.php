<?php

namespace Tests\Feature;

use App\Domain\Notifications\NotificationEmailPreference;
use App\Domain\Notifications\NotificationType;
use App\Models\NotificationDelivery;
use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class NotificationEmailPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_preference_mode_is_enum_cast_and_delivery_dedupe_is_unique(): void
    {
        $user = User::factory()->create();
        $preference = NotificationPreference::create([
            'user_id' => $user->id,
            'type' => NotificationType::NOTE_EDITED->value,
            'mode' => NotificationEmailPreference::OFF,
        ]);

        $this->assertSame(NotificationEmailPreference::OFF, $preference->refresh()->mode);

        NotificationDelivery::create([
            'user_id' => $user->id,
            'channel' => 'email',
            'kind' => 'digest',
            'dedupe_key' => 'digest:'.$user->id.':2026-08-18T09:00:00Z',
            'status' => 'pending',
        ]);

        $this->expectException(QueryException::class);
        NotificationDelivery::create([
            'user_id' => $user->id,
            'channel' => 'email',
            'kind' => 'digest',
            'dedupe_key' => 'digest:'.$user->id.':2026-08-18T09:00:00Z',
            'status' => 'pending',
        ]);
    }

    public function test_delivery_can_represent_a_digest_before_it_has_notification_items(): void
    {
        $user = User::factory()->create();

        $delivery = NotificationDelivery::create([
            'user_id' => $user->id,
            'channel' => 'email',
            'kind' => 'digest',
            'dedupe_key' => 'digest:'.$user->id.':2026-08-18T09:01:00Z',
            'status' => 'pending',
            'payload' => ['locale' => 'en'],
        ]);

        $this->assertNull($delivery->notification_id);
        $this->assertSame(['locale' => 'en'], $delivery->refresh()->payload);
    }
}
