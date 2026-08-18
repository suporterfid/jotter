<?php

namespace Tests\Feature;

use App\Models\Note;
use App\Models\PdfExport;
use App\Models\Tenant;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ProcessPdfExportsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_processes_one_queued_export_per_limit(): void
    {
        [$workspace, $notes] = $this->workspaceWithNotes();
        $first = PdfExport::create([
            'workspace_id' => $workspace->id,
            'scope' => 'workspace',
            'requested_by_subject' => '1',
            'note_ids' => $notes->pluck('id')->all(),
            'queued_at' => now()->subMinute(),
        ]);
        $second = PdfExport::create([
            'workspace_id' => $workspace->id,
            'scope' => 'workspace',
            'requested_by_subject' => '1',
            'note_ids' => $notes->pluck('id')->all(),
        ]);

        $this->artisan('pdf:process-exports', ['--limit' => 1])->assertSuccessful();

        $this->assertSame('ready', $first->fresh()->status);
        $this->assertNotEmpty($first->fresh()->getRawOriginal('output_path'));
        $this->assertSame('queued', $second->fresh()->status);
    }

    public function test_ready_export_is_not_processed_again(): void
    {
        [$workspace, $notes] = $this->workspaceWithNotes();
        $export = PdfExport::create([
            'workspace_id' => $workspace->id,
            'scope' => 'workspace',
            'status' => 'ready',
            'requested_by_subject' => '1',
            'note_ids' => $notes->pluck('id')->all(),
            'output_path' => 'already-ready.pdf',
        ]);

        $this->artisan('pdf:process-exports', ['--limit' => 1])->assertSuccessful();

        $this->assertSame('ready', $export->fresh()->status);
        $this->assertSame('already-ready.pdf', $export->fresh()->getRawOriginal('output_path'));
    }

    /** @return array{0: Workspace, 1: \Illuminate\Support\Collection<int, Note>} */
    private function workspaceWithNotes(): array
    {
        $tenant = Tenant::create(['slug' => 'default', 'name' => 'Default']);
        $vaultPath = storage_path('app/vaults/process-pdf-'.bin2hex(random_bytes(4)));
        mkdir($vaultPath, 0755, true);
        file_put_contents($vaultPath.'/first.md', '# First');
        file_put_contents($vaultPath.'/second.md', '# Second');
        $workspace = Workspace::create([
            'tenant_id' => $tenant->id,
            'slug' => 'process-pdf-'.bin2hex(random_bytes(3)),
            'name' => 'Process PDF',
            'vault_path' => $vaultPath,
        ]);
        $this->artisan('vault:reindex', ['--workspace' => $workspace->id]);

        return [$workspace, Note::query()->where('workspace_id', $workspace->id)->orderBy('path')->get()];
    }
}
