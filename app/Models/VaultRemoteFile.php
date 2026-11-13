<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A file of the remote head as Jotter last saw it (path → blob SHA). */
class VaultRemoteFile extends Model
{
    protected $table = 'vault_remote_files';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }
}
