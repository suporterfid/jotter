<?php

namespace App\Models;

use App\Domain\Vault\NotePropertyType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class NoteProperty extends Model
{
    use HasFactory;

    protected $fillable = [
        'workspace_id',
        'note_id',
        'name',
        'type',
        'value_string',
        'value_numeric',
        'value_boolean',
        'value_datetime',
        'value_json',
    ];

    protected $casts = [
        'type' => NotePropertyType::class,
        'value_numeric' => 'float',
        'value_boolean' => 'boolean',
        'value_datetime' => 'datetime',
        'value_json' => 'array',
    ];

    public function note(): BelongsTo
    {
        return $this->belongsTo(Note::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
