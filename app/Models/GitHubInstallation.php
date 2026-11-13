<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A GitHub App installation a tenant admin completed through the install flow
 * (single-use `state`). A store may only use an installation bound to its tenant.
 */
class GitHubInstallation extends Model
{
    protected $table = 'github_installations';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'installation_id' => 'integer',
            'suspended_at' => 'datetime',
        ];
    }
}
