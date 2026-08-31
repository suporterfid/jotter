<?php

namespace Tests\Feature;

use App\Domain\Notifications\NotificationType;
use App\Mail\NotificationEmail;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class ProcessNotificationDeliveriesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_deliveries_are_sent_up_to_the_limit(): void
    {
        Mail::fake();
        config(['mail.default' => 'array']);
        $recipient = User::factory()->create();
        $first = $this->pendingDelivery($recipient, now()->subMinutes(2));
        $second = $this->pendingDelivery($recipient, now()->subMinute());

        $this->artisan('notifications:process-deliveries', ['--limit' => 1])
            ->expectsOutputToContain('Processed 1 delivery(ies): 1 sent, 0 failed.')
            ->assertSuccessful();

        $this->assertSame('sent', $first->fresh()->status);
        $this->assertSame('pending', $second->fresh()->status);
        Mail::assertSent(NotificationEmail::class, 1);
    }

    public function test_sent_deliveries_are_not_processed_again(): void
    {
        Mail::fake();
        config(['mail.default' => 'array']);
        $recipient = User::factory()->create();
        $delivery = $this->pendingDelivery($recipient, now());
        $delivery->update(['status' => 'sent', 'sent_at' => now()]);

        $this->artisan('notifications:process-deliveries')
            ->expectsOutputToContain('Processed 0 delivery(ies)')
            ->assertSuccessful();

        Mail::assertNothingSent();
    }

    private function pendingDelivery(User $recipient, \DateTimeInterface $dispatchedAt): NotificationDelivery
    {
        $tenant = Tenant::create(['slug' => 'deliveries-'.uniqid(), 'name' => 'Deliveries']);
        $workspace = Workspace::create([
            'tenant_id' => $tenant->id,
            'slug' => 'deliveries-'.uniqid(),
            'name' => 'Deliveries workspace',
            'vault_path' => storage_path('app/vaults/deliveries-'.uniqid()),
        ]);
        $notification = Notification::create([
            'workspace_id' => $workspace->id,
            'user_id' => $recipient->id,
            'type' => NotificationType::MENTION->value,
            'title' => 'Notification title',
            'data' => ['note_title' => 'Watched note', 'note_path' => 'watched.md', 'target_kind' => 'note'],
        ]);

        return NotificationDelivery::create([
            'user_id' => $recipient->id,
            'notification_id' => $notification->id,
            'channel' => 'email',
            'kind' => 'immediate',
            'dedupe_key' => 'notification:'.$notification->id.':email',
            'status' => 'pending',
            'payload' => ['notification_id' => $notification->id],
            'dispatched_at' => $dispatchedAt,
        ]);
    }
}
