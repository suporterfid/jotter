<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One write saved in Jotter and not yet committed to a remote store (D3,
 * table `vault_journal`). Pending rows (`flushed_at` null) of the same path
 * coalesce while no flush has claimed them (`flush_id` null).
 *
 * @property string|null $content
 */
class VaultJournalEntry extends Model
{
    public const WRITE = 'write';

    public const DELETE = 'delete';

    protected $table = 'vault_journal';

    protected $guarded = [];

    protected $hidden = ['content'];

    protected function casts(): array
    {
        return [
            'flushed_at' => 'datetime',
            'size' => 'integer',
            'attempts' => 'integer',
        ];
    }
}
