<?php

namespace App\Console\Commands;

use App\Domain\Vault\Move\VaultMover;
use Illuminate\Console\Command;

/** Cron step of vault:move (#437): copy a bounded batch of every move in progress. */
final class VaultProcessMovesCommand extends Command
{
    protected $signature = 'vault:process-moves {--limit= : Files to copy in this run (default JOTTER_VAULT_MOVE_BATCH)}';

    protected $description = 'Copy the next batch of files of every vault move in progress, verifying each one.';

    public function handle(VaultMover $mover): int
    {
        $limit = max(1, (int) ($this->option('limit') ?: config('jotter.vault_stores.move_batch_size', 200)));

        foreach ($mover->processPending($limit) as $move) {
            $this->line(sprintf('Move #%d (workspace %d): %s, %d/%d files.', $move->id, $move->workspace_id, $move->status, $move->files_copied, $move->files_total));
        }

        return self::SUCCESS;
    }
}
