<?php

namespace App\Console\Commands;

use App\Domain\Provisioning\TenantDeletion;
use App\Domain\Provisioning\TenantExporter;
use App\Models\Tenant;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Deletes a hosted customer at the end of the retention period (LGPD). See
 * TenantDeletion for exactly what goes and what stays; `--dry-run` prints it.
 */
final class TenantDeleteCommand extends Command
{
    protected $signature = 'tenant:delete {slug : Tenant slug}
        {--export-to= : Write a tenant:export ZIP here first; nothing is deleted if the export fails}
        {--dry-run : Show what would be deleted and change nothing}
        {--force : Do not ask for confirmation}
        {--json : Emit JSON}';

    protected $description = 'Delete a tenant: its workspaces, vault files, audit history and the accounts that only belong to it.';

    public function handle(TenantDeletion $deletion, TenantExporter $exporter): int
    {
        $tenant = Tenant::query()->where('slug', $this->argument('slug'))->first();
        if ($tenant === null) {
            $this->error(sprintf('Tenant [%s] was not found.', $this->argument('slug')));

            return self::FAILURE;
        }

        $plan = $deletion->plan($tenant);
        if ($this->option('dry-run')) {
            $this->option('json')
                ? $this->line((string) json_encode(['dry_run' => true] + $plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))
                : $this->printPlan($plan);

            return self::SUCCESS;
        }

        if (! $this->option('force')) {
            $this->printPlan($plan);
            if (! $this->confirm(sprintf('Delete tenant %s for good? This cannot be undone.', $tenant->slug), false)) {
                $this->comment('Nothing was changed.');

                return self::FAILURE;
            }
        }

        $exportPath = null;
        if ($this->option('export-to')) {
            $exportPath = (string) $this->option('export-to');
            if (str_starts_with($exportPath, public_path())) {
                $this->error('Refusing to write the export under the document root.');

                return self::FAILURE;
            }
            $exportPath = (string) $exporter->export($tenant, $exportPath)['path'];
        }

        try {
            $counts = $deletion->delete($tenant, 'cli:tenant:delete');
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode(['deleted' => $plan['tenant'], 'export' => $exportPath] + $counts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Deleted tenant %s: %d workspace(s), %d account(s), %d audit row(s); %d access record(s) kept for the Marco Civil window; %d vault folder(s) removed.',
            $plan['tenant'],
            $counts['workspaces'],
            $counts['users_deleted'],
            $counts['audit_deleted'],
            $counts['access_records_kept'],
            $counts['vaults_removed'],
        ));
        if ($exportPath !== null) {
            $this->line('  export: '.$exportPath);
        }
        foreach ($counts['vaults_skipped'] as $path) {
            $this->warn('  not removed (outside the vault base path or missing): '.$path);
        }
        foreach ($counts['remote_vaults'] ?? [] as $remote) {
            $this->line(sprintf('  %s %s: %s', str_starts_with($remote['repository'], 's3://') ? 'S3' : 'GitHub', $remote['repository'], $remote['status']));
            $this->warn('  '.$remote['guidance']);
        }
        $this->comment('Instance backups keep this tenant until they rotate out (JOTTER_BACKUP_KEEP).');

        return self::SUCCESS;
    }

    /**
     * @param  array{tenant: string, workspaces: list<array{slug: string, vault_path: string, removable: bool}>, delete_users: list<string>, keep_users: list<string>}  $plan
     */
    private function printPlan(array $plan): void
    {
        $this->line(sprintf('Tenant %s', $plan['tenant']));
        foreach ($plan['workspaces'] as $workspace) {
            $this->line(sprintf('  workspace %s → %s%s', $workspace['slug'], $workspace['vault_path'], $workspace['removable'] ? '' : ' (folder kept: outside the vault base path or missing)'));
        }
        foreach ($plan['remote_vaults'] ?? [] as $repository) {
            $this->line(str_starts_with($repository, 's3://')
                ? sprintf('  S3 location %s → every object deleted (old versions stay until the bucket lifecycle expires them)', $repository)
                : sprintf('  GitHub repository %s → every file removed in a final commit (history stays: archive or delete it on GitHub)', $repository));
        }
        $this->line('  accounts deleted: '.($plan['delete_users'] === [] ? 'none' : implode(', ', $plan['delete_users'])));
        $this->line('  accounts kept (administrators or members elsewhere): '.($plan['keep_users'] === [] ? 'none' : implode(', ', $plan['keep_users'])));
    }
}
