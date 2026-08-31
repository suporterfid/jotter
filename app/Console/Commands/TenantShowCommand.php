<?php

namespace App\Console\Commands;

use App\Domain\Plan\TenantPlan;
use App\Models\Tenant;
use Illuminate\Console\Command;

final class TenantShowCommand extends Command
{
    protected $signature = 'tenant:show {slug : Tenant slug} {--json : Emit JSON}';

    protected $description = 'Show a tenant with its hosted-mode plan state, seats, and workspaces.';

    public function handle(TenantPlan $tenantPlan): int
    {
        $tenant = Tenant::query()->where('slug', $this->argument('slug'))->first();
        if ($tenant === null) {
            $this->error(sprintf('Tenant [%s] was not found.', $this->argument('slug')));

            return self::FAILURE;
        }

        $report = [
            'id' => $tenant->id,
            'slug' => $tenant->slug,
            'name' => $tenant->name,
            'created_at' => $tenant->created_at?->toIso8601String(),
            'plan' => $tenantPlan->payload($tenant) + ['seats_used' => $tenantPlan->seatsUsed($tenant)],
            'workspaces' => $tenant->workspaces()->orderBy('id')->get(['id', 'slug', 'name'])
                ->map(static fn ($workspace): array => ['id' => $workspace->id, 'slug' => $workspace->slug, 'name' => $workspace->name])
                ->all(),
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line(sprintf('Tenant %s — %s (id %d)', $tenant->slug, $tenant->name, $tenant->id));
        foreach ($report['plan'] as $key => $value) {
            $this->line(sprintf('  %-16s %s', $key, is_bool($value) ? ($value ? 'true' : 'false') : ($value ?? '—')));
        }
        $this->line(sprintf('  %-16s %d', 'workspaces', count($report['workspaces'])));
        foreach ($report['workspaces'] as $workspace) {
            $this->line(sprintf('    - %s (%s, id %d)', $workspace['name'], $workspace['slug'], $workspace['id']));
        }

        return self::SUCCESS;
    }
}
