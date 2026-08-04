<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NoteChecklistItem extends Model
{
    protected $fillable = [
        'note_id',
        'text',
        'done',
        'sort_position',
    ];

    protected function casts(): array
    {
        return [
            'done' => 'boolean',
        ];
    }

    public function note(): BelongsTo
    {
        return $this->belongsTo(Note::class);
    }
}
