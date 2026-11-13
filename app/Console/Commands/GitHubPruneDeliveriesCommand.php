<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Forget webhook delivery ids older than 24 h and expired install states (#440, #439). */
final class GitHubPruneDeliveriesCommand extends Command
{
    protected $signature = 'github:prune-deliveries';

    protected $description = 'Delete GitHub webhook delivery ids older than 24 hours and expired install states.';

    public function handle(): int
    {
        $deliveries = DB::table('github_webhook_deliveries')->where('received_at', '<', now()->subDay())->delete();
        $states = DB::table('github_install_states')->where('expires_at', '<', now()->subDay())->delete();
        $this->line("Deleted {$deliveries} delivery id(s) and {$states} install state(s).");

        return self::SUCCESS;
    }
}
