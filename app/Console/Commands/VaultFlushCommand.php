<?php

namespace App\Console\Commands;

use App\Domain\Vault\Store\GitHub\GitHubFlusher;
use App\Models\TenantVaultStore;
use Illuminate\Console\Command;

/**
 * Cron step of the write-behind journal (#441): commit pending Jotter edits
 * to GitHub in batches, within the write budget. Every minute, withoutOverlapping.
 */
final class VaultFlushCommand extends Command
{
    protected $signature = 'vault:flush
        {--store= : Only this vault store id}
        {--now : Ignore the retry backoff of the store}
        {--seconds=50 : Stop starting new batches after this many seconds}';

    protected $description = 'Commit pending edits of remote vault stores (GitHub) in batches.';

    public function handle(GitHubFlusher $flusher): int
    {
        $deadline = microtime(true) + max(1, (int) $this->option('seconds'));

        if ($this->option('store')) {
            $store = TenantVaultStore::query()->where('driver', 'github')->find((int) $this->option('store'));
            if ($store === null) {
                $this->error('No GitHub vault store with that id.');

                return self::FAILURE;
            }
            $results = [['store' => (int) $store->id] + $flusher->flush($store, deadline: $deadline, ignoreBackoff: (bool) $this->option('now'))];
        } else {
            $results = $flusher->flushAll($deadline);
        }

        foreach ($results as $result) {
            $this->line(sprintf('Store #%d: %s, %d commit(s), %d file(s).', $result['store'], $result['status'], $result['commits'], $result['files']));
        }

        return self::SUCCESS;
    }
}
