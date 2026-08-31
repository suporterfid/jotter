<?php

namespace App\Console\Commands;

use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Plan\PlanStatus;
use App\Domain\Plan\TenantPlan;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Operator entry point for hosted-mode plan state. Billing is external; this
 * command is how a paid, lapsed, or trial account is reflected in the engine.
 */
final class TenantPlanCommand extends Command
{
    protected $signature = 'tenant:plan {slug : Tenant slug}
                            {--status= : self_hosted|trial|active|past_due|read_only}
                            {--trial-days= : Trial length in days from now (implies --status=trial when omitted)}
                            {--seats= : Seat limit; pass 0 or an empty value to remove it}
                            {--name= : Plan label shown to operators}
                            {--json : Emit the resulting tenant as JSON}';

    protected $description = 'Set the hosted-mode plan status, trial deadline, seat limit, and plan name of a tenant.';

    public function handle(TenantPlan $tenantPlan, AuditRecorder $auditRecorder): int
    {
        $tenant = Tenant::query()->where('slug', $this->argument('slug'))->first();
        if ($tenant === null) {
            $this->error(sprintf('Tenant [%s] was not found.', $this->argument('slug')));

            return self::FAILURE;
        }

        $before = $tenantPlan->payload($tenant);
        $changes = [];

        $status = $this->option('status');
        if ($status !== null && $status !== '') {
            $parsed = PlanStatus::tryFrom((string) $status);
            if ($parsed === null) {
                $this->error(sprintf('Invalid --status [%s]. Allowed: %s.', $status, implode(', ', PlanStatus::values())));

                return self::FAILURE;
            }
            $changes['plan_status'] = $parsed->value;
        }

        $trialDays = $this->option('trial-days');
        if ($trialDays !== null && $trialDays !== '') {
            if (! ctype_digit((string) $trialDays) || (int) $trialDays < 1) {
                $this->error('--trial-days must be a positive integer.');

                return self::FAILURE;
            }
            $changes['trial_ends_at'] = CarbonImmutable::now()->addDays((int) $trialDays);
            if (! isset($changes['plan_status'])) {
                $changes['plan_status'] = PlanStatus::TRIAL->value;
            }
        }

        // Omitted options are null; `--seats=` (empty) and `--seats=0` clear the limit.
        if ($this->option('seats') !== null) {
            $seats = (string) $this->option('seats');
            if ($seats === '' || $seats === '0') {
                $changes['plan_seats'] = null;
            } elseif (! ctype_digit($seats)) {
                $this->error('--seats must be a non-negative integer.');

                return self::FAILURE;
            } else {
                $changes['plan_seats'] = (int) $seats;
            }
        }

        if ($this->option('name') !== null) {
            $name = trim((string) $this->option('name'));
            $changes['plan_name'] = $name === '' ? null : $name;
        }

        if ($changes === []) {
            $this->error('Nothing to change. Pass at least one of --status, --trial-days, --seats, --name.');

            return self::FAILURE;
        }

        // Leaving trial for a non-trial state clears a stale deadline.
        if (isset($changes['plan_status'])
            && $changes['plan_status'] !== PlanStatus::TRIAL->value
            && ! isset($changes['trial_ends_at'])) {
            $changes['trial_ends_at'] = null;
        }

        $tenant->forceFill($changes)->save();
        $tenant->refresh();
        $after = $tenantPlan->payload($tenant);

        $auditRecorder->record(
            event: AuditEvent::TENANT_PLAN_CHANGED,
            tenantId: $tenant->id,
            actorId: 'cli:tenant:plan',
            metadata: [
                'tenant_slug' => $tenant->slug,
                'before' => $before,
                'after' => $after,
            ],
        );

        if ($this->option('json')) {
            $this->line((string) json_encode($this->describe($tenant, $tenantPlan), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info(sprintf('Tenant %s (%s) updated.', $tenant->slug, $tenant->name));
        foreach ($this->describe($tenant, $tenantPlan)['plan'] as $key => $value) {
            $this->line(sprintf('  %-16s %s', $key, is_bool($value) ? ($value ? 'true' : 'false') : ($value ?? '—')));
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(Tenant $tenant, TenantPlan $tenantPlan): array
    {
        return [
            'id' => $tenant->id,
            'slug' => $tenant->slug,
            'name' => $tenant->name,
            'plan' => $tenantPlan->payload($tenant) + ['seats_used' => $tenantPlan->seatsUsed($tenant)],
        ];
    }
}
