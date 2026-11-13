<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Sync cursor of one remote store (plan §5.1 `vault_sync_cursors`). */
class VaultSyncState extends Model
{
    protected $table = 'vault_sync_states';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'initialized' => 'boolean',
            'pull_pending' => 'boolean',
            'last_pull_at' => 'datetime',
            'last_flush_at' => 'datetime',
            'next_flush_at' => 'datetime',
            'failing_since' => 'datetime',
            'failure_notified_at' => 'datetime',
            'last_webhook_at' => 'datetime',
            'warnings' => 'array',
            'flush_failures' => 'integer',
            'repo_size_kb' => 'integer',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(TenantVaultStore::class, 'vault_store_id');
    }

    public static function for(TenantVaultStore $store): self
    {
        return self::query()->firstOrCreate(['vault_store_id' => $store->id]);
    }

    /**
     * @param  array<string, mixed>  $warning
     */
    public function addWarning(string $key, array $warning): void
    {
        $warnings = $this->warnings ?? [];
        $warnings[$key] = $warning + ['at' => now()->toIso8601String()];
        // Bounded: the doctor shows the latest ones.
        if (count($warnings) > 200) {
            $warnings = array_slice($warnings, -200, null, true);
        }
        $this->warnings = $warnings;
    }
}
