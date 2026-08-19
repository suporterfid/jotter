<?php

namespace App\Models;

use App\Domain\Review\NoteReviewState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NoteReviewWorkflow extends Model
{
    protected $fillable = [
        'note_id',
        'reviewer_id',
        'state',
        'submitted_by_id',
        'submitted_at',
        'approved_content_hash',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'state' => NoteReviewState::class,
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    public function note(): BelongsTo
    {
        return $this->belongsTo(Note::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_id');
    }
}
