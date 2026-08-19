<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\AuditRollup;
use App\Models\AuditRollupCursor;
use App\Models\Note;
use App\Models\Tenant;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AuditRollupCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_rollup_processes_a_bounded_batch_into_note_actor_and_event_dimensions(): void
    {
        $workspace = $this->workspaceFixture();
        $note11 = $this->noteFixture($workspace, 'eleven');
        $note12 = $this->noteFixture($workspace, 'twelve');
        $first = $this->audit($workspace, 'note.updated', $note11->id, 'user:1');
        $second = $this->audit($workspace, 'note.updated', $note11->id, 'user:1');
        $third = $this->audit($workspace, 'note.created', $note12->id, 'user:2');

        $this->artisan('analytics:rollup', ['--batch' => 2])
            ->expectsOutputToContain('Processed 2 audit rows')
            ->assertExitCode(0);

        $this->assertSame(2, $this->rollupCount($workspace, 'note', (string) $note11->id));
        $this->assertSame(2, $this->rollupCount($workspace, 'actor', 'user:1'));
        $this->assertSame(2, $this->rollupCount($workspace, 'event', 'note.updated'));
        $this->assertSame(0, $this->rollupCount($workspace, 'note', (string) $note12->id));
        $this->assertDatabaseHas('audit_rollup_cursors', [
            'stream' => 'audit_log',
            'last_audit_id' => $second->id,
        ]);
        $this->assertGreaterThan($second->id, $third->id);
        $this->assertNotNull(AuditRollupCursor::query()->where('stream', 'audit_log')->first());
    }

    public function test_rollup_is_idempotent_and_survives_source_pruning(): void
    {
        $workspace = $this->workspaceFixture();
        $note = $this->noteFixture($workspace, 'eleven');
        $audit = $this->audit($workspace, 'note.updated', $note->id, 'user:1');

        $this->artisan('analytics:rollup')->assertExitCode(0);
        $this->artisan('analytics:rollup')->assertExitCode(0);

        $this->assertSame(1, $this->rollupCount($workspace, 'note', (string) $note->id));
        $this->assertSame(1, $this->rollupCount($workspace, 'actor', 'user:1'));
        $this->assertSame(1, $this->rollupCount($workspace, 'event', 'note.updated'));

        DB::table('audit_log')->where('id', $audit->id)->delete();

        $this->assertDatabaseMissing('audit_log', ['id' => $audit->id]);
        $this->assertSame(1, $this->rollupCount($workspace, 'note', (string) $note->id));
        $this->assertSame(1, $this->rollupCount($workspace, 'event', 'note.updated'));
    }

    public function test_rollup_skips_non_workspace_rows_and_keeps_workspaces_isolated(): void
    {
        $workspace = $this->workspaceFixture();
        $otherWorkspace = $this->workspaceFixture();
        $workspaceNote = $this->noteFixture($workspace, 'workspace-note');
        $otherNote = $this->noteFixture($otherWorkspace, 'other-note');
        $workspaceAudit = $this->audit($workspace, 'note.updated', $workspaceNote->id, 'user:1');
        $systemAudit = $this->audit(null, 'system.audit_pruned', null, null);
        $otherAudit = $this->audit($otherWorkspace, 'note.created', $otherNote->id, 'user:2');

        $this->artisan('analytics:rollup', ['--batch' => 10])
            ->expectsOutputToContain('skipped 1 audit rows')
            ->assertExitCode(0);

        $this->assertSame(1, $this->rollupCount($workspace, 'note', (string) $workspaceNote->id));
        $this->assertSame(1, $this->rollupCount($otherWorkspace, 'note', (string) $otherNote->id));
        $this->assertSame(0, AuditRollup::query()->whereNull('workspace_id')->count());
        $this->assertDatabaseHas('audit_rollup_cursors', [
            'stream' => 'audit_log',
            'last_audit_id' => $otherAudit->id,
        ]);
        $this->assertGreaterThan($workspaceAudit->id, $systemAudit->id);
    }

    private function rollupCount(Workspace $workspace, string $dimension, string $key): int
    {
        return (int) AuditRollup::query()
            ->where('workspace_id', $workspace->id)
            ->where('dimension', $dimension)
            ->where('dimension_key', $key)
            ->value('count');
    }

    private function workspaceFixture(): Workspace
    {
        $tenant = Tenant::query()->create([
            'slug' => 'analytics-'.bin2hex(random_bytes(3)),
            'name' => 'Analytics tenant',
        ]);

        return Workspace::query()->create([
            'tenant_id' => $tenant->id,
            'slug' => 'workspace-'.bin2hex(random_bytes(3)),
            'name' => 'Analytics workspace',
            'vault_path' => storage_path('app/vaults/analytics-'.bin2hex(random_bytes(3))),
        ]);
    }

    private function noteFixture(Workspace $workspace, string $name): Note
    {
        return Note::query()->create([
            'workspace_id' => $workspace->id,
            'path' => $name.'.md',
            'title' => $name,
            'frontmatter' => [],
            'content_hash' => hash('sha256', $name),
            'search_content' => $name,
        ]);
    }

    private function audit(?Workspace $workspace, string $event, ?int $noteId, ?string $actor): AuditLog
    {
        return AuditLog::query()->create([
            'tenant_id' => $workspace?->tenant_id,
            'workspace_id' => $workspace?->id,
            'note_id' => $noteId,
            'actor_subject_id' => $actor,
            'event' => $event,
            'metadata' => [],
            'ip_address' => '127.0.0.1',
        ]);
    }
}
