<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class NotificationDelivery extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'notification_id',
        'channel',
        'kind',
        'dedupe_key',
        'status',
        'payload',
        'claimed_at',
        'sent_at',
        'failed_at',
        'error',
    ];

    protected $casts = [
        'payload' => 'array',
        'claimed_at' => 'datetime',
        'sent_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function notification(): BelongsTo
    {
        return $this->belongsTo(Notification::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(NotificationDeliveryItem::class, 'delivery_id');
    }
}
