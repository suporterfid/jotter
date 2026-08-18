<?php

namespace Tests\Feature;

use App\Domain\Jobs\Contracts\JobDispatcher;
use App\Domain\Notifications\NotificationEmailPreference;
use App\Domain\Notifications\NotificationType;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\NotificationDeliveryItem;
use App\Models\NotificationPreference;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class SendNotificationDigestCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_digest_command_is_idempotent_for_the_same_minute(): void
    {
        Carbon::setTestNow('2026-08-18 09:15:00');
        $dispatcher = new DigestRecordingJobDispatcher;
        $this->app->instance(JobDispatcher::class, $dispatcher);
        $recipient = User::factory()->create();
        $notification = $this->makeNotification($recipient);
        NotificationPreference::create([
            'user_id' => $recipient->id,
            'type' => $notification->type,
            'mode' => NotificationEmailPreference::DIGEST,
        ]);

        $this->artisan('notifications:send-digest')->assertSuccessful();
        $this->artisan('notifications:send-digest')->assertSuccessful();

        $this->assertDatabaseCount('notification_deliveries', 1);
        $this->assertDatabaseCount('notification_delivery_items', 1);
        $this->assertCount(1, $dispatcher->jobs);
    }

    public function test_digest_command_obeys_the_per_recipient_limit(): void
    {
        Carbon::setTestNow('2026-08-18 09:16:00');
        $dispatcher = new DigestRecordingJobDispatcher;
        $this->app->instance(JobDispatcher::class, $dispatcher);
        $recipient = User::factory()->create();
        NotificationPreference::create([
            'user_id' => $recipient->id,
            'type' => NotificationType::NOTE_EDITED->value,
            'mode' => NotificationEmailPreference::DIGEST,
        ]);

        for ($index = 0; $index < 3; $index++) {
            $notification = $this->makeNotification($recipient, $index);
        }

        $this->artisan('notifications:send-digest', ['--limit' => 2])->assertSuccessful();

        $this->assertDatabaseCount('notification_delivery_items', 2);
        $this->assertCount(1, $dispatcher->jobs);
    }

    private function makeNotification(User $recipient, int $index = 0): Notification
    {
        $tenant = Tenant::create(['slug' => 'digest-'.uniqid(), 'name' => 'Digest']);
        $workspace = Workspace::create([
            'tenant_id' => $tenant->id,
            'slug' => 'digest-'.uniqid(),
            'name' => 'Digest workspace',
            'vault_path' => storage_path('app/vaults/digest-'.uniqid()),
        ]);

        return Notification::create([
            'workspace_id' => $workspace->id,
            'user_id' => $recipient->id,
            'type' => NotificationType::NOTE_EDITED->value,
            'title' => 'Digest notification '.$index,
            'data' => ['note_title' => 'Digest note '.$index],
        ]);
    }
}

final class DigestRecordingJobDispatcher implements JobDispatcher
{
    /** @var list<array{jobClass: string, payload: array<string, mixed>, workspaceId: ?int}> */
    public array $jobs = [];

    public function dispatch(string $jobClass, array $payload, ?int $workspaceId = null): string
    {
        $this->jobs[] = compact('jobClass', 'payload', 'workspaceId');

        return 'digest-job-'.count($this->jobs);
    }
}
