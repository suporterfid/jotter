<?php

namespace Tests\Feature;

use App\Domain\Jobs\Contracts\JobDispatcher;
use App\Domain\Notifications\NotificationEmailPreference;
use App\Domain\Notifications\NotificationType;
use App\Mail\NotificationEmail;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\NotificationPreference;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class NotificationEmailServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_immediate_preference_dispatches_a_localized_mailable_once(): void
    {
        Mail::fake();
        config(['mail.default' => 'array']);
        $dispatcher = new RecordingJobDispatcher;
        $recipient = User::factory()->create(['locale' => 'pt-BR']);
        $notification = $this->makeNotification($recipient, NotificationType::MENTION);
        NotificationPreference::create([
            'user_id' => $recipient->id,
            'type' => NotificationType::MENTION->value,
            'mode' => NotificationEmailPreference::IMMEDIATE,
        ]);

        $service = new \App\Domain\Notifications\NotificationEmailService($dispatcher);
        $service->enqueueImmediate($notification);
        $service->enqueueImmediate($notification);

        $this->assertCount(1, $dispatcher->jobs);
        $delivery = NotificationDelivery::query()->sole();
        $service->sendDelivery($delivery);

        Mail::assertSent(NotificationEmail::class, fn (NotificationEmail $mail): bool => $mail->locale === 'pt-BR');
        $this->assertNotNull($delivery->refresh()->sent_at);
    }

    public function test_digest_preference_does_not_dispatch_an_immediate_email(): void
    {
        Mail::fake();
        $dispatcher = new RecordingJobDispatcher;
        $recipient = User::factory()->create();
        $notification = $this->makeNotification($recipient, NotificationType::NOTE_EDITED);
        NotificationPreference::create([
            'user_id' => $recipient->id,
            'type' => NotificationType::NOTE_EDITED->value,
            'mode' => NotificationEmailPreference::DIGEST,
        ]);

        $service = new \App\Domain\Notifications\NotificationEmailService($dispatcher);
        $this->assertNull($service->enqueueImmediate($notification));

        $this->assertCount(0, $dispatcher->jobs);
        $this->assertDatabaseCount('notification_deliveries', 0);
    }

    public function test_off_preference_does_not_dispatch_an_email(): void
    {
        $dispatcher = new RecordingJobDispatcher;
        $recipient = User::factory()->create();
        $notification = $this->makeNotification($recipient, NotificationType::NOTE_DELETED);
        NotificationPreference::create([
            'user_id' => $recipient->id,
            'type' => NotificationType::NOTE_DELETED->value,
            'mode' => NotificationEmailPreference::OFF,
        ]);

        $service = new \App\Domain\Notifications\NotificationEmailService($dispatcher);
        $this->assertNull($service->enqueueImmediate($notification));

        $this->assertCount(0, $dispatcher->jobs);
        $this->assertDatabaseCount('notification_deliveries', 0);
    }

    private function makeNotification(User $recipient, NotificationType $type): Notification
    {
        $tenant = Tenant::create(['slug' => 'email-'.uniqid(), 'name' => 'Email']);
        $workspace = Workspace::create([
            'tenant_id' => $tenant->id,
            'slug' => 'email-'.uniqid(),
            'name' => 'Email workspace',
            'vault_path' => storage_path('app/vaults/email-'.uniqid()),
        ]);

        return Notification::create([
            'workspace_id' => $workspace->id,
            'user_id' => $recipient->id,
            'type' => $type->value,
            'title' => 'Notification title',
            'data' => [
                'note_title' => 'Watched note',
                'note_path' => 'watched.md',
                'target_kind' => 'note',
            ],
        ]);
    }
}

final class RecordingJobDispatcher implements JobDispatcher
{
    /** @var list<array{jobClass: string, payload: array<string, mixed>, workspaceId: ?int}> */
    public array $jobs = [];

    public function dispatch(string $jobClass, array $payload, ?int $workspaceId = null): string
    {
        $this->jobs[] = [
            'jobClass' => $jobClass,
            'payload' => $payload,
            'workspaceId' => $workspaceId,
        ];

        return 'test-job-'.count($this->jobs);
    }
}
