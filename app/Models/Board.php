<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Board extends Model
{
    protected $fillable = [
        'workspace_id',
        'name',
        'group_property',
        'filter_property',
        'filter_value',
        'column_config',
        'sort_position',
    ];

    protected function casts(): array
    {
        return [
            'column_config' => 'array',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
