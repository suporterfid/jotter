<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MachineToken extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'subject_id',
        'name',
        'token_hash',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'revoked_at' => 'datetime',
        ];
    }

    public static function hashToken(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }
}
