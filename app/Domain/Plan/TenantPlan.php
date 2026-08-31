<?php

namespace App\Domain\Plan;

use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditRecorder;
use App\Models\Membership;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Hosted-mode plan rules. With the default `self_hosted` status every method is
 * a no-op, so a self-hosted installation never observes this class. Billing is
 * external: the operator changes state with `tenant:plan`; nothing here charges.
 */
final class TenantPlan
{
    public function __construct(
        private readonly AuditRecorder $auditRecorder = new AuditRecorder,
    ) {}

    public function status(Tenant $tenant): PlanStatus
    {
        return PlanStatus::tryFrom((string) $tenant->plan_status) ?? PlanStatus::SELF_HOSTED;
    }

    public function allowsWrites(Tenant $tenant): bool
    {
        return $this->status($tenant)->allowsWrites($tenant->trial_ends_at, CarbonImmutable::now());
    }

    public function isSelfHosted(Tenant $tenant): bool
    {
        return $this->status($tenant) === PlanStatus::SELF_HOSTED;
    }

    /**
     * Whole days left in an active trial; null outside a dated trial.
     */
    public function trialDaysLeft(Tenant $tenant): ?int
    {
        if ($this->status($tenant) !== PlanStatus::TRIAL || $tenant->trial_ends_at === null) {
            return null;
        }

        $now = CarbonImmutable::now();

        return $tenant->trial_ends_at->greaterThan($now)
            ? (int) ceil($now->diffInSeconds($tenant->trial_ends_at) / 86400)
            : 0;
    }

    /**
     * Distinct subjects holding at least one membership in the tenant.
     */
    public function seatsUsed(Tenant $tenant): int
    {
        return Membership::query()
            ->where('tenant_id', $tenant->id)
            ->distinct()
            ->count('subject_id');
    }

    /**
     * Whether granting membership to $subjectId would exceed `plan_seats`.
     * Subjects that already hold a membership never consume a new seat.
     */
    public function seatLimitReached(Tenant $tenant, string $subjectId): bool
    {
        $seats = $tenant->plan_seats;
        if ($seats === null || $this->isSelfHosted($tenant)) {
            return false;
        }

        $alreadyMember = Membership::query()
            ->where('tenant_id', $tenant->id)
            ->where('subject_id', $subjectId)
            ->exists();
        if ($alreadyMember) {
            return false;
        }

        return $this->seatsUsed($tenant) >= (int) $seats;
    }

    /**
     * Payload exposed to the SPA (trial banner, read-only notice).
     *
     * @return array{status: string, name: ?string, seats: ?int, trial_ends_at: ?string, trial_days_left: ?int, read_only: bool}
     */
    public function payload(Tenant $tenant): array
    {
        return [
            'status' => $this->status($tenant)->value,
            'name' => $tenant->plan_name,
            'seats' => $tenant->plan_seats,
            'trial_ends_at' => $tenant->trial_ends_at?->toIso8601String(),
            'trial_days_left' => $this->trialDaysLeft($tenant),
            'read_only' => ! $this->allowsWrites($tenant),
        ];
    }

    /**
     * 402 response for a blocked write, recorded in the audit log.
     */
    public function denyWrite(Tenant $tenant, Request $request, ?int $workspaceId, ?string $actorId): JsonResponse
    {
        $this->auditRecorder->record(
            event: AuditEvent::PLAN_WRITE_BLOCKED,
            tenantId: $tenant->id,
            workspaceId: $workspaceId,
            actorId: $actorId,
            metadata: [
                'plan_status' => $this->status($tenant)->value,
                'path' => $request->path(),
                'method' => $request->method(),
            ],
        );

        return response()->json([
            'message' => __('messages.plan_read_only'),
            'plan_status' => $this->status($tenant)->value,
        ], 402);
    }

    /**
     * 402 response for a membership beyond `plan_seats`, recorded in the audit log.
     */
    public function denySeat(Tenant $tenant, ?int $workspaceId, ?string $actorId, string $targetSubjectId): JsonResponse
    {
        $this->auditRecorder->record(
            event: AuditEvent::PLAN_SEAT_LIMIT_REACHED,
            tenantId: $tenant->id,
            workspaceId: $workspaceId,
            actorId: $actorId,
            metadata: [
                'plan_seats' => $tenant->plan_seats,
                'seats_used' => $this->seatsUsed($tenant),
                'target_subject_id' => $targetSubjectId,
            ],
        );

        return response()->json([
            'message' => __('messages.plan_seat_limit_reached', ['seats' => (int) $tenant->plan_seats]),
            'plan_status' => $this->status($tenant)->value,
            'plan_seats' => $tenant->plan_seats,
        ], 402);
    }

    /**
     * Moves every trial whose deadline passed to `read_only`, one audit row each.
     *
     * @return list<string> slugs that were expired
     */
    public function expireTrials(): array
    {
        $expired = [];
        $now = CarbonImmutable::now();

        Tenant::query()
            ->where('plan_status', PlanStatus::TRIAL->value)
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<=', $now)
            ->orderBy('id')
            ->each(function (Tenant $tenant) use (&$expired, $now): void {
                $tenant->forceFill(['plan_status' => PlanStatus::READ_ONLY->value])->save();

                $this->auditRecorder->record(
                    event: AuditEvent::TENANT_TRIAL_EXPIRED,
                    tenantId: $tenant->id,
                    metadata: [
                        'tenant_slug' => $tenant->slug,
                        'trial_ends_at' => $tenant->trial_ends_at?->toIso8601String(),
                        'expired_at' => $now->toIso8601String(),
                        'from' => PlanStatus::TRIAL->value,
                        'to' => PlanStatus::READ_ONLY->value,
                    ],
                );

                $expired[] = $tenant->slug;
            });

        return $expired;
    }
}
