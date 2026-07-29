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

        // Regression: workspace-scoped events must show up even when the
        // recorder call site never populated tenant_id — this is the normal
        // case in practice (e.g. WorkspaceEventEmitter::emitMention() only
        // ever passes workspaceId). Requiring an exact tenant_id match on
        // top of workspace_id silently hid every one of these.
        AuditLog::create([
            'workspace_id' => $workspace->id,
            'actor_subject_id' => (string) $admin->id,
            'event' => 'note.updated',
            'metadata' => ['path' => 'welcome.md'],
            'ip_address' => '127.0.0.1',
        ]);

        // A log for a different workspace must not leak in.
        $otherWorkspace = Workspace::create([
            'tenant_id' => $tenant->id,
            'slug' => 'other',
            'name' => 'Other',
            'vault_path' => storage_path('app/vaults/audit_test_other'),
        ]);
        AuditLog::create([
            'workspace_id' => $otherWorkspace->id,
            'event' => 'note.created',
            'metadata' => [],
            'ip_address' => '127.0.0.1',
        ]);

        $response = $this->actingAs($admin)
            ->getJson("/api/workspaces/{$workspace->id}/audit-logs");

        $response->assertOk()
            ->assertJsonStructure([
                'workspace_id',
                'audit_logs',
            ])
            ->assertJsonCount(1, 'audit_logs')
            ->assertJsonPath('audit_logs.0.event', 'note.updated');
    }
}
