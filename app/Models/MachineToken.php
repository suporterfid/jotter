<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MachineToken extends Model
{
    use HasFactory;

    public const KIND_MANUAL = 'manual';
    public const KIND_OAUTH = 'oauth';

    /**
     * Least privilege by default: a token created without explicit scopes is a
     * read-only MCP token (same default the 2026-11-03 migration backfills).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'kind' => self::KIND_MANUAL,
        'scopes' => '["mcp:read"]',
    ];

    protected $fillable = [
        'tenant_id',
        'subject_id',
        'user_id',
        'name',
        'kind',
        'scopes',
        'workspace_ids',
        'write_paths',
        'client_id',
        'client_slug',
        'oauth_grant_id',
        'token_hash',
        'revoked_at',
        'expires_at',
        'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'user_id' => 'integer',
            'scopes' => 'array',
            'workspace_ids' => 'array',
            'write_paths' => 'array',
            'client_id' => 'integer',
            'oauth_grant_id' => 'integer',
            'revoked_at' => 'datetime',
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    public static function hashToken(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }
}
