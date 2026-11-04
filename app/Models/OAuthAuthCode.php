<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OAuthAuthCode extends Model
{
    protected $table = 'oauth_auth_codes';

    protected $fillable = ['code_hash', 'oauth_grant_id', 'redirect_uri', 'code_challenge', 'resource', 'expires_at', 'used_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'used_at' => 'datetime'];
    }

    public function grant(): BelongsTo
    {
        return $this->belongsTo(OAuthGrant::class, 'oauth_grant_id');
    }
}
