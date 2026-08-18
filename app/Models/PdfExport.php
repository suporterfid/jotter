<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class PdfExport extends Model
{
    protected $fillable = [
        'workspace_id',
        'note_id',
        'scope',
        'status',
        'requested_by_subject',
        'note_ids',
        'dispatcher_job_id',
        'output_path',
        'failure_reason',
        'queued_at',
        'started_at',
        'completed_at',
        'expires_at',
    ];

    protected $hidden = [
        'output_path',
    ];

    protected $casts = [
        'note_ids' => 'array',
        'queued_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    protected $attributes = [
        'scope' => 'workspace',
        'status' => 'queued',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function note(): BelongsTo
    {
        return $this->belongsTo(Note::class);
    }

    protected static function booted(): void
    {
        static::creating(function (self $export): void {
            $export->id ??= (string) Str::uuid();
            $export->queued_at ??= now();
        });
    }
}
