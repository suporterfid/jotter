<?php

namespace App\Console\Commands;

use App\Domain\Vault\Store\GitHub\GitHubSync;
use App\Models\TenantVaultStore;
use App\Models\VaultSyncState;
use Illuminate\Console\Command;

/**
 * Brings changes made outside Jotter in (#440). The push webhook does this in
 * seconds; this command is the safety net: `--pending` every minute for stores
 * the webhook could not finish, every store every 5 minutes. A ref that did
 * not change answers 304 and costs no quota.
 */
final class VaultPullCommand extends Command
{
    protected $signature = 'vault:pull
        {--store= : Only this vault store id}
        {--pending : Only stores marked pull_pending (webhook not finished)}
        {--files=500 : Files applied per store in this run}
        {--seconds=50 : Stop after this many seconds}';

    protected $description = 'Pull changes made outside Jotter from remote vault stores (GitHub).';

    public function handle(GitHubSync $sync): int
    {
        $deadline = microtime(true) + max(1, (int) $this->option('seconds'));
        $query = TenantVaultStore::query()->where('driver', 'github')->where('status', '!=', TenantVaultStore::STATUS_FAILED);
        if ($this->option('store')) {
            $query = TenantVaultStore::query()->where('driver', 'github')->whereKey((int) $this->option('store'));
        } elseif ($this->option('pending')) {
            $query->whereIn('id', VaultSyncState::query()->where('pull_pending', true)->pluck('vault_store_id'));
        } else {
            // Only stores a workspace uses (or a move is filling) need polling.
            $query->where(fn ($q) => $q->whereIn('id', \App\Models\Workspace::query()->whereNotNull('vault_store_id')->pluck('vault_store_id'))
                ->orWhereIn('id', VaultSyncState::query()->where('pull_pending', true)->pluck('vault_store_id')));
        }

        foreach ($query->orderBy('id')->get() as $store) {
            if (microtime(true) > $deadline) {
                break;
            }
            $result = $sync->pull($store, max(1, (int) $this->option('files')), $deadline);
            $this->line(sprintf('Store #%d: %s, %d file(s), %d conflict(s).', $store->id, $result['status'], $result['applied'], $result['conflicts']));
        }

        return self::SUCCESS;
    }
}
