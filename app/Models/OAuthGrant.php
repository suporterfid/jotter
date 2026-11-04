<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What a person consented to for one OAuth client: tenant, workspaces, scopes,
 * write paths, client slug, validity. Access and refresh tokens hang off it;
 * revoking the grant revokes them all.
 */
class OAuthGrant extends Model
{
    protected $table = 'oauth_grants';

    protected $fillable = ['tenant_id', 'subject_id', 'user_id', 'oauth_client_id', 'workspace_ids', 'scopes', 'write_paths', 'client_slug', 'expires_at', 'revoked_at'];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'user_id' => 'integer',
            'workspace_ids' => 'array',
            'scopes' => 'array',
            'write_paths' => 'array',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(OAuthClient::class, 'oauth_client_id');
    }

    public function accessTokens(): HasMany
    {
        return $this->hasMany(MachineToken::class, 'oauth_grant_id');
    }

    public function refreshTokens(): HasMany
    {
        return $this->hasMany(OAuthRefreshToken::class, 'oauth_grant_id');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
