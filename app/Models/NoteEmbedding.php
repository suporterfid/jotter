<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One embedded chunk of a note (#412). A rebuildable projection: see
 * SemanticIndexer and the `semantic:index` command.
 */
class NoteEmbedding extends Model
{
    protected $table = 'note_embeddings';

    protected $fillable = ['workspace_id', 'note_id', 'chunk_index', 'content_hash', 'model', 'dimensions', 'chunk_text', 'vector'];

    protected $hidden = ['vector'];

    public function note(): BelongsTo
    {
        return $this->belongsTo(Note::class);
    }
}
