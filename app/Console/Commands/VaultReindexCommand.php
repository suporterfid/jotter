<?php

namespace App\Console\Commands;

use App\Domain\Vault\VaultReindexer;
use App\Models\Workspace;
use Illuminate\Console\Command;

class VaultReindexCommand extends Command
{
    protected $signature = 'vault:reindex
                            {--workspace= : Workspace id to reconcile}
                            {--all : Reconcile every workspace (scheduler mode)}
                            {--batch= : Optional batch size override for shared-hosting limits}';

    protected $description = 'Rebuild the notes projection for a workspace from on-disk Markdown (bounded/batched)';

    public function handle(VaultReindexer $reindexer): int
    {
        $workspaceId = $this->option('workspace');
        if ($this->option('all')) {
            return $this->reindexAll($reindexer);
        }

        if ($workspaceId === null || $workspaceId === '') {
            $this->error('The --workspace option is required (or pass --all).');

            return self::FAILURE;
        }

        if (! ctype_digit((string) $workspaceId)) {
            $this->error('The --workspace option must be a positive integer id.');

            return self::FAILURE;
        }

        $workspace = Workspace::query()->find((int) $workspaceId);
        if ($workspace === null) {
            $this->error("Workspace [{$workspaceId}] was not found.");

            return self::FAILURE;
        }

        $batchOption = $this->option('batch');
        $batchSize = null;
        if ($batchOption !== null && $batchOption !== '') {
            if (! ctype_digit((string) $batchOption) || (int) $batchOption < 1) {
                $this->error('The --batch option must be a positive integer.');

                return self::FAILURE;
            }
            $batchSize = (int) $batchOption;
        }

        $result = $reindexer->reindex($workspace, $batchSize);

        $this->info(sprintf(
            'Reindexed workspace %d (%s): scanned %d, upserted %d, removed %d.',
            $workspace->id,
            $workspace->slug,
            $result['scanned'],
            $result['upserted'],
            $result['removed'],
        ));

        return self::SUCCESS;
    }

    private function reindexAll(VaultReindexer $reindexer): int
    {
        $count = 0;
        Workspace::query()->orderBy('id')->each(function (Workspace $workspace) use ($reindexer, &$count): void {
            $result = $reindexer->reindex($workspace, null);
            $count++;

            $this->line(sprintf(
                'Reindexed workspace %d (%s): scanned %d, upserted %d, removed %d.',
                $workspace->id,
                $workspace->slug,
                $result['scanned'],
                $result['upserted'],
                $result['removed'],
            ));
        });

        $this->info("Reindexed {$count} workspace(s).");

        return self::SUCCESS;
    }
}
