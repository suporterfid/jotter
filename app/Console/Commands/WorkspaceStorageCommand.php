<?php

namespace App\Console\Commands;

use App\Domain\Quota\WorkspaceQuota;
use App\Models\Workspace;
use Illuminate\Console\Command;

/**
 * Storage use and quotas per workspace (#390). Scheduled hourly with --measure
 * to correct the running total kept by writes.
 */
final class WorkspaceStorageCommand extends Command
{
    protected $signature = 'workspace:storage
        {workspace? : Workspace slug or id (default: all)}
        {--measure : Re-measure the vaults on disk}
        {--quota= : Set the quota in MB for the workspace (0 = unlimited, "default" = JOTTER_WORKSPACE_QUOTA_MB)}
        {--json : Emit JSON}';

    protected $description = 'Show workspace storage use against the quota, re-measure it, or set a workspace quota.';

    public function handle(WorkspaceQuota $quota): int
    {
        $reference = (string) $this->argument('workspace');
        $query = Workspace::query()->orderBy('id');
        if ($reference !== '') {
            $query->where(static fn ($q) => $q->where('slug', $reference)->when(ctype_digit($reference), static fn ($q) => $q->orWhere('id', (int) $reference)));
        }
        $workspaces = $query->get();
        if ($reference !== '' && $workspaces->isEmpty()) {
            $this->error(sprintf('Workspace [%s] was not found.', $reference));

            return self::FAILURE;
        }

        $setQuota = $this->option('quota');
        if ($setQuota !== null) {
            if ($reference === '') {
                $this->error('--quota needs a workspace.');

                return self::FAILURE;
            }
            if ($setQuota !== 'default' && ! ctype_digit((string) $setQuota)) {
                $this->error('--quota takes a number of MB, 0 for unlimited, or "default".');

                return self::FAILURE;
            }
            $workspaces->first()->forceFill(['storage_quota_mb' => $setQuota === 'default' ? null : (int) $setQuota])->save();
        }

        $rows = [];
        foreach ($workspaces as $workspace) {
            if ($this->option('measure')) {
                $quota->measure($workspace);
            }
            $status = $quota->status($workspace);
            $rows[] = ['id' => $workspace->id, 'slug' => $workspace->slug, 'quota_mb' => $workspace->storage_quota_mb] + $status;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($rows, JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->table(['workspace', 'used', 'quota', '%', 'state'], array_map(static fn (array $row): array => [
            $row['slug'],
            WorkspaceQuota::human($row['used_bytes']),
            $row['limit_bytes'] === null ? 'unlimited' : WorkspaceQuota::human($row['limit_bytes']).($row['quota_mb'] === null ? ' (default)' : ''),
            $row['percent'] === null ? '—' : $row['percent'].'%',
            $row['exceeded'] ? 'FULL' : ($row['warning'] ? 'warning' : 'ok'),
        ], $rows));

        return self::SUCCESS;
    }
}
