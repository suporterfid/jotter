<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The GitHub App registered from the admin panel (manifest flow). The private
 * key and webhook secret are encrypted at rest and never leave the server.
 */
class GitHubAppRegistration extends Model
{
    protected $table = 'github_app_registrations';

    protected $guarded = [];

    protected $hidden = ['private_key', 'webhook_secret'];

    protected function casts(): array
    {
        return [
            'private_key' => 'encrypted',
            'webhook_secret' => 'encrypted',
        ];
    }
}
