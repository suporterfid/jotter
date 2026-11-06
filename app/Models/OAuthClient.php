<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OAuthClient extends Model
{
    protected $table = 'oauth_clients';

    public const KIND_DCR = 'dcr';
    public const KIND_CIMD = 'cimd';

    protected $fillable = ['client_id', 'kind', 'name', 'redirect_uris', 'auth_method', 'secret_hash', 'registered_ip', 'metadata_expires_at', 'last_used_at'];

    protected $hidden = ['secret_hash'];

    protected function casts(): array
    {
        return ['redirect_uris' => 'array', 'metadata_expires_at' => 'datetime', 'last_used_at' => 'datetime'];
    }

    public function grants(): HasMany
    {
        return $this->hasMany(OAuthGrant::class, 'oauth_client_id');
    }
}
