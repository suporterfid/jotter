<?php

namespace App\Domain\Auth;

use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditRecorder;
use App\Models\MachineToken;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Issues machine tokens for MCP clients. The plaintext (`jt_mkt_...`) is returned
 * once and only its SHA-256 hash is stored; the token acts as the given user and
 * inherits exactly that user's workspace memberships.
 */
final class MachineTokenIssuer
{
    public const PREFIX = 'jt_mkt_';

    public function __construct(
        private readonly AuditRecorder $auditRecorder = new AuditRecorder,
    ) {}

    /**
     * @return array{token: MachineToken, plain: string}
     */
    public function issue(User $user, Tenant $tenant, string $name, ?string $actorId = null): array
    {
        $plain = self::PREFIX.Str::random(40);

        $token = MachineToken::create([
            'tenant_id' => $tenant->id,
            'subject_id' => (string) $user->id,
            'name' => trim($name),
            'token_hash' => MachineToken::hashToken($plain),
        ]);

        $this->auditRecorder->record(
            event: AuditEvent::MACHINE_TOKEN_CREATED,
            tenantId: $tenant->id,
            actorId: $actorId,
            metadata: ['token_id' => $token->id, 'name' => $token->name, 'subject_id' => $token->subject_id],
        );

        return ['token' => $token, 'plain' => $plain];
    }

    public function revoke(MachineToken $token, ?string $actorId = null): void
    {
        if ($token->revoked_at !== null) {
            return;
        }

        $token->forceFill(['revoked_at' => now()])->save();

        $this->auditRecorder->record(
            event: AuditEvent::MACHINE_TOKEN_REVOKED,
            tenantId: $token->tenant_id,
            actorId: $actorId,
            metadata: ['token_id' => $token->id, 'name' => $token->name, 'subject_id' => $token->subject_id],
        );
    }
}
