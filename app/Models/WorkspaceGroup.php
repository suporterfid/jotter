<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkspaceGroup extends Model
{
    protected $fillable = ['workspace_id', 'name'];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_group_members')
            ->withTimestamps();
    }

    public function aclEntries(): HasMany
    {
        return $this->hasMany(NoteAclEntry::class, 'principal_id')
            ->where('principal_type', 'group');
    }
}
