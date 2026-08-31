<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    protected $fillable = [
        'slug',
        'name',
        'plan_status',
        'trial_ends_at',
        'plan_name',
        'plan_seats',
    ];

    protected $casts = [
        'trial_ends_at' => 'immutable_datetime',
        'plan_seats' => 'integer',
    ];

    public function workspaces(): HasMany
    {
        return $this->hasMany(Workspace::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }
}
