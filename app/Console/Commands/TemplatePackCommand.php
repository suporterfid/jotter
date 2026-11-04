<?php

namespace App\Console\Commands;

use App\Domain\Brain\BrainPack;
use App\Domain\Provisioning\TemplatePack;
use Illuminate\Console\Command;

/**
 * Builds the starter-template ZIP so it can be uploaded through the workspace
 * import endpoint (or handed to a customer).
 */
final class TemplatePackCommand extends Command
{
    protected $signature = 'templates:pack {--locale=en : en or pt-BR} {--to= : Output ZIP path} {--pack=starter : starter (_templates/*.md) or brain (the brain vault layout)}';

    protected $description = 'Write a template pack (starter templates or the brain vault layout) as an importable ZIP.';

    public function handle(TemplatePack $pack, BrainPack $brain): int
    {
        if ((string) $this->option('pack') === 'brain') {
            $locale = BrainPack::normalizeLocale((string) $this->option('locale'));
            $to = (string) ($this->option('to') ?: storage_path('app/private/exports/brain-'.$locale.'.zip'));
            $path = $brain->writeZip($locale, $to);
            $this->info(sprintf('Brain pack (%s, %d files) written to %s', $locale, count($brain->files($locale)), $path));

            return self::SUCCESS;
        }

        if ((string) $this->option('pack') !== 'starter') {
            $this->error('Unknown --pack; use starter or brain.');

            return self::FAILURE;
        }

        $locale = TemplatePack::normalizeLocale((string) $this->option('locale'));
        $to = (string) ($this->option('to') ?: storage_path('app/private/exports/templates-'.$locale.'.zip'));
        $path = $pack->writeZip($locale, $to);

        $this->info(sprintf('Template pack (%s, %d files) written to %s', $locale, count($pack->files($locale)), $path));

        return self::SUCCESS;
    }
}
