<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class NotificationDeliveryItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'delivery_id',
        'notification_id',
        'channel',
    ];

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(NotificationDelivery::class, 'delivery_id');
    }

    public function notification(): BelongsTo
    {
        return $this->belongsTo(Notification::class);
    }
}
