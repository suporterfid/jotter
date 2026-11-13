<?php

namespace App\Domain\Vault\Store\GitHub;

use App\Models\TenantVaultStore;
use Illuminate\Support\Facades\DB;

/**
 * Immutable file contents by Git blob SHA (`vault_blob_cache`), in MySQL so
 * shared hosting spends no inodes. Least recently used rows go once a store
 * passes its cap (50 MB by default). Never part of a backup: it is rebuilt
 * from GitHub on demand.
 */
class GitHubBlobCache
{
    private const TABLE = 'vault_blob_cache';

    public function get(TenantVaultStore $store, string $sha): ?string
    {
        $row = DB::table(self::TABLE)->where('vault_store_id', $store->id)->where('sha', $sha)->first(['id', 'content']);
        if ($row === null) {
            return null;
        }

        DB::table(self::TABLE)->where('id', $row->id)->update(['last_used_at' => now()]);

        return (string) $row->content;
    }

    public function put(TenantVaultStore $store, string $sha, string $contents): void
    {
        $cap = max(0, (int) config('jotter.github.blob_cache_bytes', 50 * 1024 * 1024));
        if (strlen($contents) > $cap) {
            return;
        }

        DB::table(self::TABLE)->insertOrIgnore([
            'vault_store_id' => $store->id,
            'sha' => $sha,
            'size' => strlen($contents),
            'content' => $contents,
            'last_used_at' => now(),
        ]);

        $this->evict($store, $cap);
    }

    public function forget(TenantVaultStore $store): void
    {
        DB::table(self::TABLE)->where('vault_store_id', $store->id)->delete();
    }

    public function size(TenantVaultStore $store): int
    {
        return (int) DB::table(self::TABLE)->where('vault_store_id', $store->id)->sum('size');
    }

    private function evict(TenantVaultStore $store, int $cap): void
    {
        $total = $this->size($store);
        if ($total <= $cap) {
            return;
        }

        $rows = DB::table(self::TABLE)->where('vault_store_id', $store->id)
            ->orderBy('last_used_at')->orderBy('id')->get(['id', 'size']);
        $drop = [];
        foreach ($rows as $row) {
            if ($total <= $cap) {
                break;
            }
            $drop[] = $row->id;
            $total -= (int) $row->size;
        }
        if ($drop !== []) {
            DB::table(self::TABLE)->whereIn('id', $drop)->delete();
        }
    }
}
