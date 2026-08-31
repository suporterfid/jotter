<?php

namespace App\Console\Commands;

use App\Domain\Vault\ImportSource;
use App\Domain\Vault\VaultExtractor;
use App\Domain\Vault\VaultReindexer;
use App\Models\Workspace;
use Illuminate\Console\Command;

final class VaultImportCommand extends Command
{
    protected $signature = 'vault:import {workspace : Workspace ID or slug} {archive : Path to ZIP archive} {--overwrite : Overwrite existing notes} {--source=generic : generic, obsidian, or notion (normalizes exported paths)}';

    protected $description = 'Import a vault archive into a workspace and rebuild the index';

    public function handle(VaultExtractor $extractor, VaultReindexer $reindexer): int
    {
        $workspaceId = $this->argument('workspace');
        $archivePath = $this->argument('archive');
        $overwrite = (bool) $this->option('overwrite');

        $workspace = Workspace::where('id', $workspaceId)
            ->orWhere('slug', $workspaceId)
            ->first();

        if (! $workspace) {
            $this->error("Workspace not found: {$workspaceId}");

            return self::FAILURE;
        }

        if (! file_exists($archivePath)) {
            $this->error("Archive file not found: {$archivePath}");

            return self::FAILURE;
        }

        $this->info("Extracting archive into workspace '{$workspace->name}'...");
        $result = $extractor->extract($workspace, $archivePath, $overwrite, ImportSource::fromInput($this->option('source')));

        $this->info('Reindexing workspace vault...');
        $reindexer->reindex($workspace);

        $extractedCount = count($result['extracted']);
        $skippedCount = count($result['skipped']);
        $this->info("Import completed successfully. Extracted: {$extractedCount}, Skipped: {$skippedCount}.");

        return self::SUCCESS;
    }
}
