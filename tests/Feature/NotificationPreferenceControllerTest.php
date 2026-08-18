<?php

namespace Tests\Feature;

use App\Domain\Notifications\NotificationEmailPreference;
use App\Domain\Notifications\NotificationType;
use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class NotificationPreferenceControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_preferences_return_safe_defaults_for_each_notification_type(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/user/notification-preferences');

        $response->assertOk();
        $response->assertJsonPath('data.0.type', NotificationType::MENTION->value);
        $response->assertJsonPath('data.0.mode', NotificationEmailPreference::IMMEDIATE->value);
        $response->assertJsonPath('data.0.explicit', false);
        $response->assertJsonFragment([
            'type' => NotificationType::NOTE_EDITED->value,
            'mode' => NotificationEmailPreference::DIGEST->value,
            'explicit' => false,
        ]);
    }

    public function test_user_can_update_one_preference_without_affecting_another_user(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($user)
            ->putJson('/api/user/notification-preferences/note_edited', ['mode' => 'off'])
            ->assertOk()
            ->assertJson([
                'data' => [
                    'type' => 'note_edited',
                    'mode' => 'off',
                    'explicit' => true,
                ],
            ]);

        $this->assertDatabaseHas('notification_preferences', [
            'user_id' => $user->id,
            'type' => 'note_edited',
            'mode' => 'off',
        ]);
        $this->assertDatabaseMissing('notification_preferences', ['user_id' => $other->id]);
    }

    public function test_invalid_type_and_mode_are_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->putJson('/api/user/notification-preferences/not_a_notification', ['mode' => 'off'])
            ->assertUnprocessable();

        $this->actingAs($user)
            ->putJson('/api/user/notification-preferences/mention', ['mode' => 'sometimes'])
            ->assertUnprocessable();
    }

    public function test_preferences_require_authentication(): void
    {
        $this->getJson('/api/user/notification-preferences')->assertUnauthorized();
    }
}
