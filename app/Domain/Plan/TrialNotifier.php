<?php

namespace App\Domain\Plan;

use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditRecorder;
use App\Mail\TrialEndedEmail;
use App\Mail\TrialReminderEmail;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Scheduler-driven trial e-mails, sent synchronously from the CLI (no queue
 * worker on shared hosting). Each tenant receives every message at most once,
 * tracked by `trial_reminder_sent_at` / `trial_ended_notified_at`.
 */
final class TrialNotifier
{
    public const REMINDER_DAYS = 3;

    public function __construct(
        private readonly TenantPlan $tenantPlan,
        private readonly AuditRecorder $auditRecorder = new AuditRecorder,
    ) {}

    /**
     * Reminds owners of trials ending within REMINDER_DAYS.
     *
     * @return list<string> slugs reminded
     */
    public function sendReminders(): array
    {
        $now = CarbonImmutable::now();
        $reminded = [];

        Tenant::query()
            ->where('plan_status', PlanStatus::TRIAL->value)
            ->whereNull('trial_reminder_sent_at')
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '>', $now)
            ->where('trial_ends_at', '<=', $now->addDays(self::REMINDER_DAYS))
            ->orderBy('id')
            ->each(function (Tenant $tenant) use (&$reminded): void {
                $daysLeft = max(1, (int) $this->tenantPlan->trialDaysLeft($tenant));
                $recipients = $this->recipients($tenant);
                foreach ($recipients as $user) {
                    $this->deliver($user, new TrialReminderEmail($user, $tenant, $daysLeft));
                }

                $tenant->forceFill(['trial_reminder_sent_at' => CarbonImmutable::now()])->save();
                $this->auditRecorder->record(
                    event: AuditEvent::TENANT_TRIAL_REMINDER_SENT,
                    tenantId: $tenant->id,
                    metadata: ['tenant_slug' => $tenant->slug, 'days_left' => $daysLeft, 'recipients' => $recipients->count()],
                );
                $reminded[] = $tenant->slug;
            });

        return $reminded;
    }

    /**
     * Tells owners that a trial ended (tenant already moved to read_only).
     *
     * @param  iterable<Tenant>  $tenants
     * @return list<string> slugs notified
     */
    public function sendEnded(iterable $tenants): array
    {
        $notified = [];
        foreach ($tenants as $tenant) {
            if ($tenant->trial_ended_notified_at !== null) {
                continue;
            }
            foreach ($this->recipients($tenant) as $user) {
                $this->deliver($user, new TrialEndedEmail($user, $tenant));
            }
            $tenant->forceFill(['trial_ended_notified_at' => CarbonImmutable::now()])->save();
            $notified[] = $tenant->slug;
        }

        return $notified;
    }

    /**
     * Owners and admins of any workspace in the tenant (local users only).
     *
     * @return Collection<int, User>
     */
    public function recipients(Tenant $tenant): Collection
    {
        $ids = Membership::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('role', ['owner', 'admin'])
            ->pluck('subject_id')
            ->filter(static fn ($id): bool => ctype_digit((string) $id))
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values();

        return User::query()->whereIn('id', $ids)->where('is_active', true)->orderBy('id')->get();
    }

    private function deliver(User $user, TrialReminderEmail|TrialEndedEmail $mailable): void
    {
        try {
            Mail::to($user->email)->send($mailable);
        } catch (\Throwable $exception) {
            Log::error('trial_email_failed', ['user_id' => $user->id, 'mailable' => $mailable::class, 'exception' => $exception]);
        }
    }
}
