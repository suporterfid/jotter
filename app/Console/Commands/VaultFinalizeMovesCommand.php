<?php

namespace App\Console\Commands;

use App\Domain\Vault\Move\VaultMover;
use Illuminate\Console\Command;

/** Deletes the retained source of completed vault moves once their retention ends (#437, audited). */
final class VaultFinalizeMovesCommand extends Command
{
    protected $signature = 'vault:finalize-moves {--now : Ignore the retention period (no rollback afterwards)}';

    protected $description = 'Delete the retained source of completed vault moves whose retention ended.';

    public function handle(VaultMover $mover): int
    {
        $finalized = $mover->finalizeDue((bool) $this->option('now'), 'cli:vault:finalize-moves');
        $this->info(sprintf('%d move%s finalized.', count($finalized), count($finalized) === 1 ? '' : 's'));

        return self::SUCCESS;
    }
}
