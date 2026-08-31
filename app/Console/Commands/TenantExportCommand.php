<?php

namespace App\Console\Commands;

use App\Domain\Provisioning\TenantExporter;
use App\Models\Tenant;
use Illuminate\Console\Command;

final class TenantExportCommand extends Command
{
    protected $signature = 'tenant:export {slug : Tenant slug} {--to= : Output ZIP path (default: storage/app/private/exports/<slug>-<timestamp>.zip)} {--json : Emit JSON}';

    protected $description = 'Export a whole tenant (vault files, JSON backups, manifest) as one portable ZIP.';

    public function handle(TenantExporter $exporter): int
    {
        $tenant = Tenant::query()->where('slug', $this->argument('slug'))->first();
        if ($tenant === null) {
            $this->error(sprintf('Tenant [%s] was not found.', $this->argument('slug')));

            return self::FAILURE;
        }

        $to = (string) ($this->option('to') ?: storage_path('app/private/exports/'.$tenant->slug.'-'.now()->format('Ymd-His').'.zip'));
        if (str_starts_with($to, public_path())) {
            $this->error('Refusing to write the export under the document root.');

            return self::FAILURE;
        }

        $summary = $exporter->export($tenant, $to);

        if ($this->option('json')) {
            $this->line((string) json_encode(['tenant' => $tenant->slug] + $summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info(sprintf('Exported tenant %s: %d workspace(s), %d file(s), %s.', $tenant->slug, $summary['workspaces'], $summary['files'], $this->humanBytes($summary['bytes'])));
        $this->line('  '.$summary['path']);

        return self::SUCCESS;
    }

    private function humanBytes(int $bytes): string
    {
        $units = ['B', 'KiB', 'MiB', 'GiB'];
        $value = (float) $bytes;
        $unit = 0;
        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return sprintf($unit === 0 ? '%d %s' : '%.1f %s', $value, $units[$unit]);
    }
}
