<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A storage location a tenant registered for its workspaces' vaults (table
 * `vault_stores`, #436). `credentials` is encrypted with APP_KEY and hidden
 * from serialization: the API only ever says whether it is set.
 *
 * @property array<string, mixed>|null $settings
 * @property array<string, mixed>|null $credentials
 */
class TenantVaultStore extends Model
{
    public const STATUS_UNTESTED = 'untested';

    public const STATUS_OK = 'ok';

    public const STATUS_FAILED = 'failed';

    protected $table = 'vault_stores';

    protected $fillable = [
        'tenant_id',
        'name',
        'driver',
        'settings',
        'credentials',
        'status',
        'last_checked_at',
        'last_error',
    ];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'credentials' => 'encrypted:array',
            'last_checked_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function workspaces(): HasMany
    {
        return $this->hasMany(Workspace::class, 'vault_store_id');
    }

    /**
     * Credentials, or null when they cannot be decrypted (APP_KEY rotated
     * without APP_PREVIOUS_KEYS): the store must be reconnected.
     *
     * @return array<string, mixed>|null
     */
    public function decryptedCredentials(): ?array
    {
        try {
            return $this->credentials ?? [];
        } catch (\Illuminate\Contracts\Encryption\DecryptException) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'name' => $this->name,
            'driver' => $this->driver,
            'settings' => $this->settings ?? (object) [],
            'has_credentials' => $this->getRawOriginal('credentials') !== null,
            'status' => $this->status,
            'last_checked_at' => $this->last_checked_at?->toIso8601String(),
            'last_error' => $this->last_error,
            'workspaces' => $this->workspaces()->count(),
        ];
    }
}
