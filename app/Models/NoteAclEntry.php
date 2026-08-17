<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NoteAclEntry extends Model
{
    protected $table = 'note_acl_entries';

    protected $fillable = [
        'note_id',
        'principal_type',
        'principal_id',
        'permission',
    ];

    public function note(): BelongsTo
    {
        return $this->belongsTo(Note::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'principal_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(WorkspaceGroup::class, 'principal_id');
    }
}
