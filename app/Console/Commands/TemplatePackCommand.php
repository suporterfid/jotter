<?php

namespace App\Console\Commands;

use App\Domain\Provisioning\TemplatePack;
use Illuminate\Console\Command;

/**
 * Builds the starter-template ZIP so it can be uploaded through the workspace
 * import endpoint (or handed to a customer).
 */
final class TemplatePackCommand extends Command
{
    protected $signature = 'templates:pack {--locale=en : en or pt-BR} {--to= : Output ZIP path}';

    protected $description = 'Write the starter template pack (_templates/*.md) as an importable ZIP.';

    public function handle(TemplatePack $pack): int
    {
        $locale = TemplatePack::normalizeLocale((string) $this->option('locale'));
        $to = (string) ($this->option('to') ?: storage_path('app/private/exports/templates-'.$locale.'.zip'));
        $path = $pack->writeZip($locale, $to);

        $this->info(sprintf('Template pack (%s, %d files) written to %s', $locale, count($pack->files($locale)), $path));

        return self::SUCCESS;
    }
}
