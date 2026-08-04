<?php

namespace App\Domain\Audit;

use App\Models\AuditLog;

final class AuditRecorder
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        AuditEvent $event,
        ?int $tenantId = null,
        ?int $workspaceId = null,
        ?string $actorId = null,
        array $metadata = [],
        ?int $noteId = null
    ): AuditLog {
        $ip = request()->ip() ?? '127.0.0.1';

        // Central Redaction Rules (Issue #70): Scrub sensitive keys from metadata
        $cleanMetadata = $this->redactMetadata($metadata);

        return AuditLog::query()->create([
            'tenant_id' => $tenantId,
            'workspace_id' => $workspaceId,
            'note_id' => $noteId,
            'actor_subject_id' => $actorId,
            'actor_type' => $actorId ? 'user' : 'system',
            'actor_id' => $actorId,
            'event' => $event->value,
            'metadata' => $cleanMetadata,
            'ip_address' => $ip,
        ]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function redactMetadata(array $metadata): array
    {
        $sensitiveKeys = ['password', 'secret', 'token', 'authorization', 'cookie', 'authsessid'];

        foreach ($metadata as $key => $value) {
            if (in_array(strtolower($key), $sensitiveKeys, true)) {
                $metadata[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $metadata[$key] = $this->redactMetadata($value);
            }
        }

        return $metadata;
    }
}
