<?php

namespace Tests\Feature;

use App\Domain\Vault\VaultStorage;
use App\Models\Membership;
use App\Models\Note;
use App\Models\NoteAclEntry;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class NoteViewAuditTest extends TestCase
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

    public function test_note_views_are_not_recorded_by_default(): void
    {
        config(['jotter.analytics.record_reads' => false]);
        $user = User::factory()->create(['is_admin' => true]);
        [$workspace, $note] = $this->workspaceNote();

        $this->actingAs($user)
            ->getJson("/api/workspaces/{$workspace->id}/notes/{$note->id}")
            ->assertOk();

        $this->assertDatabaseMissing('audit_log', ['event' => 'note.viewed']);
    }

    public function test_successful_note_view_is_recorded_when_enabled(): void
    {
        config(['jotter.analytics.record_reads' => true]);
        $user = User::factory()->create(['is_admin' => true]);
        [$workspace, $note] = $this->workspaceNote();

        $this->actingAs($user)
            ->getJson("/api/workspaces/{$workspace->id}/notes/{$note->id}")
            ->assertOk();

        $this->assertDatabaseHas('audit_log', [
            'workspace_id' => $workspace->id,
            'note_id' => $note->id,
            'actor_subject_id' => (string) $user->id,
            'event' => 'note.viewed',
        ]);
        $this->assertSame(1, \App\Models\AuditLog::query()->where('event', 'note.viewed')->count());
    }

    public function test_denied_note_view_does_not_create_a_view_event(): void
    {
        config(['jotter.analytics.record_reads' => true]);
        $viewer = User::factory()->create(['is_admin' => false]);
        $allowedUser = User::factory()->create(['is_admin' => false]);
        [$workspace, $note] = $this->workspaceNote();

        Membership::create([
            'subject_id' => (string) $viewer->id,
            'tenant_id' => $workspace->tenant_id,
            'workspace_id' => $workspace->id,
            'role' => 'viewer',
        ]);
        NoteAclEntry::create([
            'note_id' => $note->id,
            'principal_type' => 'user',
            'principal_id' => $allowedUser->id,
            'permission' => 'view',
        ]);

        $this->actingAs($viewer)
            ->getJson("/api/workspaces/{$workspace->id}/notes/{$note->id}")
            ->assertNotFound();

        $this->assertDatabaseMissing('audit_log', [
            'note_id' => $note->id,
            'event' => 'note.viewed',
        ]);
    }

    public function test_missing_note_file_does_not_create_a_view_event_when_enabled(): void
    {
        config(['jotter.analytics.record_reads' => true]);
        $user = User::factory()->create(['is_admin' => true]);
        [$workspace, $note] = $this->workspaceNote();
        unlink($workspace->vault_path.'/'.$note->path);

        $this->actingAs($user)
            ->getJson("/api/workspaces/{$workspace->id}/notes/{$note->id}")
            ->assertNotFound();

        $this->assertDatabaseMissing('audit_log', [
            'note_id' => $note->id,
            'event' => 'note.viewed',
        ]);
    }

    /** @return array{0: Workspace, 1: Note} */
    private function workspaceNote(): array
    {
        $vaultRoot = sys_get_temp_dir().'/jotter-note-view-'.uniqid('', true);
        mkdir($vaultRoot, 0755, true);
        $this->vaultRoots[] = $vaultRoot;

        $tenant = Tenant::create([
            'slug' => 'note-view-tenant-'.uniqid(),
            'name' => 'Note View Tenant',
        ]);
        $workspace = Workspace::create([
            'tenant_id' => $tenant->id,
            'slug' => 'note-view-workspace-'.uniqid(),
            'name' => 'Note View Workspace',
            'vault_path' => $vaultRoot,
        ]);
        $note = app(VaultStorage::class)->write($workspace, 'note.md', "# Note\n");

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
