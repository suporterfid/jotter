<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One change of a workspace's vault store (vault:move, #437). A null store
 * id is the workspace's local `vault_path`.
 */
class VaultMove extends Model
{
    public const COPYING = 'copying';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    public const ROLLED_BACK = 'rolled_back';

    public const FINALIZED = 'finalized';

    protected $fillable = [
        'workspace_id', 'from_store_id', 'from_prefix', 'to_store_id', 'to_prefix', 'status', 'cursor',
        'files_total', 'bytes_total', 'files_copied', 'bytes_copied', 'error', 'actor_id',
        'started_at', 'completed_at', 'retain_until',
    ];

    protected function casts(): array
    {
        return [
            'files_total' => 'integer',
            'bytes_total' => 'integer',
            'files_copied' => 'integer',
            'bytes_copied' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'retain_until' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function fromStore(): BelongsTo
    {
        return $this->belongsTo(TenantVaultStore::class, 'from_store_id');
    }

    public function toStore(): BelongsTo
    {
        return $this->belongsTo(TenantVaultStore::class, 'to_store_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'workspace_id' => $this->workspace_id,
            'from_store_id' => $this->from_store_id,
            'to_store_id' => $this->to_store_id,
            'status' => $this->status,
            'files_total' => $this->files_total,
            'files_copied' => $this->files_copied,
            'bytes_total' => $this->bytes_total,
            'bytes_copied' => $this->bytes_copied,
            'percent' => $this->files_total > 0 ? (int) floor($this->files_copied * 100 / $this->files_total) : ($this->status === self::COPYING ? 0 : 100),
            'error' => $this->error,
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'retain_until' => $this->retain_until?->toIso8601String(),
        ];
    }
}
