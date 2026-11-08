<?php

namespace App\Domain\Quota;

use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Per-workspace storage quota (#390). The limit is `workspaces.storage_quota_mb`
 * (null: JOTTER_WORKSPACE_QUOTA_MB, default 1024; 0: unlimited). Only growth is
 * refused: a write that keeps or shrinks the vault, or any delete, always
 * passes, so a workspace over its quota can still be cleaned up.
 *
 * Usage is `workspaces.storage_bytes`: measured from disk (first use, hourly
 * `workspace:storage --measure`, after imports) and moved by each write's delta
 * in between, so a check costs one row read, not a directory walk.
 */
final class WorkspaceQuota
{
    public function limitBytes(Workspace $workspace): ?int
    {
        $mb = $workspace->storage_quota_mb ?? (int) config('jotter.quota.default_mb', 1024);

        return $mb > 0 ? (int) $mb * 1024 * 1024 : null;
    }

    public function usage(Workspace $workspace): int
    {
        return $workspace->storage_bytes ?? $this->measure($workspace);
    }

    public function measure(Workspace $workspace): int
    {
        $bytes = 0;
        $root = realpath((string) $workspace->vault_path);
        if ($root !== false && is_dir($root)) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));
            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if ($file->isFile() && ! $file->isLink()) {
                    $bytes += (int) $file->getSize();
                }
            }
        }

        $workspace->forceFill(['storage_bytes' => $bytes, 'storage_measured_at' => now()])->saveQuietly();

        return $bytes;
    }

    /**
     * @throws QuotaExceeded when adding $bytes would pass the limit
     */
    public function assertCanGrow(Workspace $workspace, int $bytes): void
    {
        $limit = $this->limitBytes($workspace);
        if ($limit === null || $bytes <= 0) {
            return;
        }

        $used = $this->usage($workspace);
        if ($used + $bytes > $limit) {
            throw new QuotaExceeded($used, $limit, $bytes);
        }
    }

    public function recordDelta(Workspace $workspace, int $delta): void
    {
        if ($delta === 0 || $workspace->storage_bytes === null) {
            return;
        }

        DB::table('workspaces')->where('id', $workspace->id)
            ->update(['storage_bytes' => DB::raw('GREATEST(0, CAST(storage_bytes AS SIGNED) + '.(int) $delta.')')]);
        $workspace->storage_bytes = max(0, (int) $workspace->storage_bytes + $delta);
    }

    /**
     * @return array{used_bytes: int, limit_bytes: int|null, percent: float|null, warning: bool, exceeded: bool}
     */
    public function status(Workspace $workspace): array
    {
        $used = $this->usage($workspace);
        $limit = $this->limitBytes($workspace);
        $percent = $limit === null ? null : round($used * 100 / $limit, 1);
        $warn = (int) config('jotter.quota.warn_percent', 80);

        return [
            'used_bytes' => $used,
            'limit_bytes' => $limit,
            'percent' => $percent,
            'warning' => $percent !== null && $percent >= $warn,
            'exceeded' => $limit !== null && $used >= $limit,
        ];
    }

    public static function human(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = (float) $bytes;
        $unit = 0;
        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return $unit === 0 ? $bytes.' B' : number_format($value, 1, ',', '.').' '.$units[$unit];
    }
}
