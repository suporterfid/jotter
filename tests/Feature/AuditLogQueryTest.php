<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AuditLogQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_logs_query_endpoint_returns_logs(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $tenant = Tenant::create(['slug' => 'default', 'name' => 'Default']);
        $workspace = Workspace::create([
            'tenant_id' => $tenant->id,
            'slug' => 'main',
            'name' => 'Main',
            'vault_path' => storage_path('app/vaults/audit_test'),
        ]);

        AuditLog::create([
            'actor_subject_id' => (string) $admin->id,
            'event' => 'note.created',
            'metadata' => ['path' => 'welcome.md'],
            'ip_address' => '127.0.0.1',
        ]);

        $response = $this->actingAs($admin)
            ->getJson("/api/workspaces/{$workspace->id}/audit-logs");

        $response->assertOk()
            ->assertJsonStructure([
                'workspace_id',
                'audit_logs',
            ]);
    }
}
