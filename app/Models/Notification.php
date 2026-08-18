<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Notification extends Model
{
    use HasFactory;

    protected $fillable = [
        'workspace_id',
        'user_id',
        'type',
        'title',
        'data',
        'dedupe_key',
        'read_at',
    ];

    protected $casts = [
        'data' => 'array',
        'read_at' => 'datetime',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function emailDeliveries(): HasMany
    {
        return $this->hasMany(NotificationDelivery::class);
    }
}
