<?php

namespace Tests\Feature;

use App\Domain\Jobs\Contracts\JobDispatcher;
use App\Models\Membership;
use App\Models\Note;
use App\Models\NoteAclEntry;
use App\Models\PdfExport;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class WorkspacePdfExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_workspace_pdf_export_is_queued_without_rendering_in_request(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        [$workspace, $notes] = $this->workspaceWithNotes();
        $dispatcher = new RecordingPdfDispatcher;
        $this->app->instance(JobDispatcher::class, $dispatcher);

        $response = $this->actingAs($admin)
            ->postJson("/api/workspaces/{$workspace->id}/pdf-exports");

        $response->assertStatus(202)->assertJson([
            'status' => 'queued',
            'scope' => 'workspace',
        ]);
        $export = PdfExport::query()->firstOrFail();
        $this->assertSame($notes->pluck('id')->sort()->values()->all(), collect($export->note_ids)->sort()->values()->all());
        $this->assertSame('App\\Jobs\\GeneratePdfExport', $dispatcher->jobClass);
        $this->assertSame(['export_id' => $export->id], $dispatcher->payload);
    }

    public function test_workspace_pdf_queue_excludes_notes_hidden_by_acl(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        [$workspace, $notes] = $this->workspaceWithNotes();
        Membership::create([
            'subject_id' => (string) $user->id,
            'tenant_id' => $workspace->tenant_id,
            'workspace_id' => $workspace->id,
            'role' => 'editor',
        ]);
        $otherUser = User::factory()->create();
        NoteAclEntry::create([
            'note_id' => $notes[1]->id,
            'principal_type' => 'user',
            'principal_id' => $otherUser->id,
            'permission' => 'view',
        ]);
        $this->app->instance(JobDispatcher::class, new RecordingPdfDispatcher);

        $response = $this->actingAs($user)
            ->postJson("/api/workspaces/{$workspace->id}/pdf-exports");

        $response->assertStatus(202);
        $export = PdfExport::query()->firstOrFail();
        $this->assertSame([$notes[0]->id], $export->note_ids);
    }

    public function test_unauthorized_workspace_pdf_export_is_forbidden(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        [$workspace] = $this->workspaceWithNotes();
        $this->app->instance(JobDispatcher::class, new RecordingPdfDispatcher);

        $this->actingAs($user)
            ->postJson("/api/workspaces/{$workspace->id}/pdf-exports")
            ->assertForbidden();
    }

    /** @return array{0: Workspace, 1: \Illuminate\Support\Collection<int, Note>} */
    private function workspaceWithNotes(): array
    {
        $tenant = Tenant::create(['slug' => 'default', 'name' => 'Default']);
        $vaultPath = storage_path('app/vaults/workspace-pdf-'.bin2hex(random_bytes(4)));
        mkdir($vaultPath, 0755, true);
        file_put_contents($vaultPath.'/first.md', '# First');
        file_put_contents($vaultPath.'/second.md', '# Second');
        $workspace = Workspace::create([
            'tenant_id' => $tenant->id,
            'slug' => 'workspace-pdf-'.bin2hex(random_bytes(3)),
            'name' => 'Workspace PDF',
            'vault_path' => $vaultPath,
        ]);
        $this->artisan('vault:reindex', ['--workspace' => $workspace->id]);

        return [$workspace, Note::query()->where('workspace_id', $workspace->id)->orderBy('path')->get()];
    }
}

final class RecordingPdfDispatcher implements JobDispatcher
{
    public string $jobClass = '';

    /** @var array<string, mixed> */
    public array $payload = [];

    public function dispatch(string $jobClass, array $payload, ?int $workspaceId = null): string
    {
        $this->jobClass = $jobClass;
        $this->payload = $payload;

        return 'dispatcher-job-1';
    }
}
