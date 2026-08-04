<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Note extends Model
{
    protected $fillable = [
        'workspace_id',
        'path',
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

    public function checklistItems(): HasMany
    {
        return $this->hasMany(NoteChecklistItem::class)->orderBy('sort_position')->orderBy('id');
    }
}
