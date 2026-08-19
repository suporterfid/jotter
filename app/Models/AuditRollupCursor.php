<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditRollupCursor extends Model
{
    protected $fillable = [
        'stream',
        'last_audit_id',
    ];

    protected function casts(): array
    {
        return [
            'last_audit_id' => 'integer',
        ];
    }
}
