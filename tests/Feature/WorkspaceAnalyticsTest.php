<?php

namespace Tests\Feature;

use App\Models\AuditRollup;
use App\Models\AuditLog;
use App\Models\Membership;
use App\Models\Note;
use App\Models\NoteAclEntry;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class WorkspaceAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $vaultRoots = [];

    protected function tearDown(): void
    {
        foreach ($this->vaultRoots as $vaultRoot) {
            $this->deleteTree($vaultRoot);
        }

        parent::tearDown();
    }

    public function test_workspace_analytics_returns_rollup_metrics_without_raw_audit_fields(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        [$workspace, $note] = $this->workspaceWithNote('metrics');
        $today = Carbon::now('UTC')->toDateString();
        $yesterday = Carbon::now('UTC')->subDay()->toDateString();

        $this->rollup($workspace, $today, 'note', (string) $note->id, 4);
        $this->rollup($workspace, $yesterday, 'event', 'note.updated', 3);
        $this->rollup($workspace, $today, 'actor', 'external:user-7', 2);
        AuditLog::query()->create([
            'workspace_id' => $workspace->id,
            'event' => 'note.updated',
            'metadata' => ['secret' => 'not-for-analytics'],
            'ip_address' => '192.0.2.10',
        ]);

        $response = $this->actingAs($admin)
            ->getJson("/api/workspaces/{$workspace->id}/analytics?days=30&limit=10")
            ->assertOk()
            ->assertJsonStructure([
                'workspace_id',
                'period' => ['days', 'from', 'to'],
                'most_active_notes',
                'activity_over_time',
                'activity_by_user',
                'stale_notes',
            ])
            ->assertJsonPath('workspace_id', $workspace->id)
            ->assertJsonPath('period.days', 30)
            ->assertJsonPath('most_active_notes.0.note_id', $note->id)
            ->assertJsonPath('most_active_notes.0.count', 4)
            ->assertJsonPath('activity_by_user.0.actor_subject_id', 'external:user-7')
            ->assertJsonPath('activity_by_user.0.count', 2);

        $response->assertJsonMissingPath('most_active_notes.0.metadata');
        $response->assertJsonMissingPath('most_active_notes.0.ip_address');
        $this->assertStringNotContainsString('not-for-analytics', $response->getContent());
        $this->assertStringNotContainsString('192.0.2.10', $response->getContent());
    }

    public function test_workspace_analytics_validates_bounded_period_and_limit(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        [$workspace] = $this->workspaceWithNote('validation');

        $this->actingAs($admin)
            ->getJson("/api/workspaces/{$workspace->id}/analytics?days=0&limit=101")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['days', 'limit']);
    }

    public function test_workspace_analytics_filters_restricted_and_other_workspace_notes(): void
    {
        $viewer = User::factory()->create(['is_admin' => false]);
        $allowedUser = User::factory()->create(['is_admin' => false]);
        [$workspace, $visibleNote] = $this->workspaceWithNote('visible');
        [, $otherNote] = $this->workspaceWithNote('other');
        $restrictedNote = app(\App\Domain\Vault\VaultStorage::class)
            ->write($workspace, 'restricted.md', '# Restricted\n');

        Membership::create([
            'subject_id' => (string) $viewer->id,
            'tenant_id' => $workspace->tenant_id,
            'workspace_id' => $workspace->id,
            'role' => 'viewer',
        ]);
        NoteAclEntry::create([
            'note_id' => $restrictedNote->id,
            'principal_type' => 'user',
            'principal_id' => $allowedUser->id,
            'permission' => 'view',
        ]);

        $today = Carbon::now('UTC')->toDateString();
        $this->rollup($workspace, $today, 'note', (string) $visibleNote->id, 3);
        $this->rollup($workspace, $today, 'note', (string) $restrictedNote->id, 99);
        $otherWorkspace = $otherNote->workspace;
        $this->rollup($otherWorkspace, $today, 'note', (string) $otherNote->id, 200);

        $response = $this->actingAs($viewer)
            ->getJson("/api/workspaces/{$workspace->id}/analytics")
            ->assertOk();

        $response->assertJsonPath('most_active_notes.0.note_id', $visibleNote->id);
        $response->assertJsonPath('most_active_notes.0.count', 3);
        $noteIds = collect($response->json('most_active_notes'))->pluck('note_id')->all();
        $this->assertNotContains($restrictedNote->id, $noteIds);
        $this->assertNotContains($otherNote->id, $noteIds);
    }

    public function test_stale_notes_include_visible_notes_without_rollup_history(): void
    {
        config(['jotter.analytics.stale_days' => 30]);
        $admin = User::factory()->create(['is_admin' => true]);
        [$workspace, $staleNote] = $this->workspaceWithNote('stale');
        [, $freshNote] = $this->workspaceWithNote('fresh');

        DB::table('notes')->where('id', $staleNote->id)->update([
            'updated_at' => Carbon::now()->subDays(45),
        ]);

        $response = $this->actingAs($admin)
            ->getJson("/api/workspaces/{$workspace->id}/analytics")
            ->assertOk();

        $response->assertJsonFragment([
            'note_id' => $staleNote->id,
            'path' => $staleNote->path,
        ]);
        $staleNoteIds = collect($response->json('stale_notes'))->pluck('note_id')->all();
        $this->assertNotContains($freshNote->id, $staleNoteIds);
    }

    private function rollup(Workspace $workspace, string $period, string $dimension, string $key, int $count): AuditRollup
    {
        return AuditRollup::query()->create([
            'workspace_id' => $workspace->id,
            'period_start' => $period,
            'dimension' => $dimension,
            'dimension_key' => $key,
            'count' => $count,
            'first_seen_at' => Carbon::parse($period, 'UTC')->startOfDay(),
            'last_seen_at' => Carbon::parse($period, 'UTC')->endOfDay(),
        ]);
    }

    /** @return array{0: Workspace, 1: Note} */
    private function workspaceWithNote(string $suffix): array
    {
        $vaultRoot = sys_get_temp_dir().'/jotter-analytics-'.uniqid('', true);
        mkdir($vaultRoot, 0755, true);
        $this->vaultRoots[] = $vaultRoot;

        $tenant = Tenant::query()->create([
            'slug' => "analytics-tenant-{$suffix}-".uniqid(),
            'name' => 'Analytics Tenant',
        ]);
        $workspace = Workspace::query()->create([
            'tenant_id' => $tenant->id,
            'slug' => "analytics-workspace-{$suffix}-".uniqid(),
            'name' => 'Analytics Workspace',
            'vault_path' => $vaultRoot,
        ]);
        $note = app(\App\Domain\Vault\VaultStorage::class)->write($workspace, 'note.md', "# {$suffix}\n");

        return [$workspace, $note];
    }

    private function deleteTree(string $path): void
    {
        if (! is_dir($path) && ! is_file($path)) {
            return;
        }

        if (is_file($path)) {
            @unlink($path);

            return;
        }

        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $this->deleteTree($path.DIRECTORY_SEPARATOR.$item);
        }

        @rmdir($path);
    }
}
