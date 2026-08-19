<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Note extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'workspace_id',
        'path',
        'original_path',
        'title',
        'frontmatter',
        'content_hash',
        'search_content',
        'sort_position',
    ];

    protected function casts(): array
    {
        return [
            'frontmatter' => 'array',
            'deleted_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function outgoingLinks(): HasMany
    {
        return $this->hasMany(NoteLink::class, 'source_note_id');
    }

    public function incomingLinks(): HasMany
    {
        return $this->hasMany(NoteLink::class, 'target_note_id');
    }

    public function incomingBlockReferences(string $blockId): HasMany
    {
        return $this->incomingLinks()->where('target_block', $blockId);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'note_tags');
    }

    public function properties(): HasMany
    {
        return $this->hasMany(NoteProperty::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(NoteComment::class);
    }

    public function watchers(): HasMany
    {
        return $this->hasMany(NoteWatcher::class);
    }

    public function checklistItems(): HasMany
    {
        return $this->hasMany(NoteChecklistItem::class)->orderBy('sort_position')->orderBy('id');
    }

    public function aclEntries(): HasMany
    {
        return $this->hasMany(NoteAclEntry::class);
    }

    public function shares(): HasMany
    {
        return $this->hasMany(NoteShare::class);
    }

    public function reviewWorkflow(): HasOne
    {
        return $this->hasOne(NoteReviewWorkflow::class);
    }
}
