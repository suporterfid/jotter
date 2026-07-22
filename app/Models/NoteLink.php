<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NoteLink extends Model
{
    protected $fillable = [
        'source_note_id',
        'target_ref',
        'target_note_id',
        'type',
    ];

    public function sourceNote(): BelongsTo
    {
        return $this->belongsTo(Note::class, 'source_note_id');
    }

    public function targetNote(): BelongsTo
    {
        return $this->belongsTo(Note::class, 'target_note_id');
    }
}
