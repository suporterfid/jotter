<?php

namespace App\Console\Commands;

use App\Models\NoteRevision;
use Illuminate\Console\Command;

final class VaultPruneRevisionsCommand extends Command
{
    protected $signature = 'vault:prune-revisions {--days=30 : Prune revisions older than N days}';

    protected $description = 'Prune old note revision history';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $cutoff = now()->subDays($days);

        $deleted = NoteRevision::query()
            ->where('created_at', '<', $cutoff)
            ->delete();

        $this->info("Pruned {$deleted} note revisions older than {$days} days.");

        return self::SUCCESS;
    }
}
