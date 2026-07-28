<?php

namespace Tests\Feature;

use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditRecorder;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;

final class AuditRecorderTest extends \Tests\TestCase
{
    use RefreshDatabase;

    public function test_audit_recorder_persists_log_and_redacts_sensitive_keys(): void
    {
        $tenant = Tenant::create(['slug' => 'test-tenant', 'name' => 'Test Tenant']);
        $workspace = Workspace::create([
            'tenant_id' => $tenant->id,
            'slug' => 'main',
            'name' => 'Main',
            'vault_path' => storage_path('app/vaults/audit_recorder_test'),
        ]);

        $recorder = new AuditRecorder();

        $log = $recorder->record(
            AuditEvent::AUTH_LOGIN_FAILURE,
            $tenant->id,
            $workspace->id,
            'user-123',
            [
                'email' => 'user@example.com',
                'password' => 'supersecret',
                'token' => 'abc123token',
            ]
        );

        $this->assertInstanceOf(AuditLog::class, $log);
        $this->assertEquals(AuditEvent::AUTH_LOGIN_FAILURE->value, $log->event);
        $this->assertEquals('[REDACTED]', $log->metadata['password']);
        $this->assertEquals('[REDACTED]', $log->metadata['token']);
        $this->assertEquals('user@example.com', $log->metadata['email']);
    }
}
