<?php

namespace App\Console\Commands;

use App\Domain\Vault\VaultReindexer;
use App\Domain\Vault\VaultStorage;
use App\Models\Workspace;
use Illuminate\Console\Command;

/**
 * Fills a workspace with the fictional "Cadernia Labs" knowledge base
 * (resources/demo/pt-BR): interlinked wiki, ADRs, meeting notes, and runbooks
 * for public demos and screenshots.
 */
final class DemoSeedCommand extends Command
{
    protected $signature = 'demo:seed {workspace-slug : Target workspace slug}
                            {--tenant= : Tenant slug when the workspace slug exists in several tenants}
                            {--overwrite : Replace notes that already exist}
                            {--locale=pt-BR : Content locale (only pt-BR is bundled)}';

    protected $description = 'Seed a workspace with ~25 interlinked demo notes (pt-BR).';

    public function handle(VaultStorage $storage, VaultReindexer $reindexer): int
    {
        $query = Workspace::query()->where('slug', $this->argument('workspace-slug'));
        if ($this->option('tenant')) {
            $query->whereHas('tenant', fn ($tenant) => $tenant->where('slug', $this->option('tenant')));
        }
        $workspaces = $query->get();

        if ($workspaces->isEmpty()) {
            $this->error(sprintf('Workspace [%s] was not found.', $this->argument('workspace-slug')));

            return self::FAILURE;
        }
        if ($workspaces->count() > 1) {
            $this->error('Several workspaces share that slug; pass --tenant=<slug>.');

            return self::FAILURE;
        }
        $workspace = $workspaces->first();

        $dir = resource_path('demo/'.$this->option('locale'));
        if (! is_dir($dir)) {
            $this->error(sprintf('No demo content for locale [%s].', $this->option('locale')));

            return self::FAILURE;
        }

        $written = 0;
        $skipped = 0;
        foreach ($this->files($dir) as $relative => $absolute) {
            if (! $this->option('overwrite') && $storage->exists($workspace, $relative)) {
                $skipped++;

                continue;
            }
            $storage->write($workspace, $relative, (string) file_get_contents($absolute), 'cli:demo:seed');
            $written++;
        }

        $reindexer->reindex($workspace, null, 'cli:demo:seed');

        $this->info(sprintf('Seeded workspace %s (%s): %d note(s) written, %d skipped.', $workspace->slug, $workspace->name, $written, $skipped));

        return self::SUCCESS;
    }

    /**
     * @return array<string, string> relative path => absolute path
     */
    private function files(string $dir): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'md') {
                $relative = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($dir))), '/');
                $files[$relative] = $file->getPathname();
            }
        }
        ksort($files);

        return $files;
    }
}
