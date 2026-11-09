<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $attributes = [
        'is_active' => true,
        'locale' => 'pt-BR',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'is_admin',
        'is_active',
        'locale',
        'signup_status',
        'signup_requested_at',
        'terms_accepted_at',
        'terms_version',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'is_active' => 'boolean',
            'signup_requested_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
        ];
    }

    public function identities(): HasMany
    {
        return $this->hasMany(Identity::class);
    }

    /**
     * Every subject id a membership may name for this account: the local id,
     * the e-mail, and linked SSO identities (OIDC `iss|sub`, GrandpaSSOn UUID).
     * Mirrors LocalIdentityProvider::resolveSubjectIds().
     *
     * @return list<string>
     */
    public function membershipSubjectIds(): array
    {
        return array_values(array_unique(array_filter([
            (string) $this->id,
            (string) $this->email,
            ...$this->identities()->pluck('subject_id')->map(static fn ($id): string => (string) $id)->all(),
        ])));
    }

    public function workspaceGroups(): BelongsToMany
    {
        return $this->belongsToMany(WorkspaceGroup::class, 'workspace_group_members')
            ->withTimestamps();
    }

    public function noteWatchers(): HasMany
    {
        return $this->hasMany(NoteWatcher::class);
    }

    public function notificationPreferences(): HasMany
    {
        return $this->hasMany(NotificationPreference::class);
    }

    public function notificationDeliveries(): HasMany
    {
        return $this->hasMany(NotificationDelivery::class);
    }
}
