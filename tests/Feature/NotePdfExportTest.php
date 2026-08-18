<?php

namespace Tests\Feature;

use App\Models\Note;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class NotePdfExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_user_can_download_a_note_as_pdf(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $workspace = $this->workspace('note-pdf');
        file_put_contents($workspace->vault_path.'/guide.md', "# Guide\n\n[[Target Note]]\n");
        $this->artisan('vault:reindex', ['--workspace' => $workspace->id]);
        $note = Note::query()->where('workspace_id', $workspace->id)->where('path', 'guide.md')->firstOrFail();

        $response = $this->actingAs($admin)
            ->get("/api/workspaces/{$workspace->id}/notes/{$note->id}/export.pdf");

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'attachment; filename=guide.pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    public function test_unauthenticated_request_cannot_download_a_note_pdf(): void
    {
        $workspace = $this->workspace('note-pdf-unauthenticated');
        file_put_contents($workspace->vault_path.'/guide.md', '# Guide');
        $this->artisan('vault:reindex', ['--workspace' => $workspace->id]);
        $note = Note::query()->where('workspace_id', $workspace->id)->where('path', 'guide.md')->firstOrFail();

        $this->get("/api/workspaces/{$workspace->id}/notes/{$note->id}/export.pdf")
            ->assertUnauthorized();
    }

    private function workspace(string $slug): Workspace
    {
        $tenant = Tenant::create(['slug' => 'default', 'name' => 'Default']);
        $vaultPath = storage_path('app/vaults/'.$slug.'-'.bin2hex(random_bytes(4)));
        mkdir($vaultPath, 0755, true);

        return Workspace::create([
            'tenant_id' => $tenant->id,
            'slug' => $slug,
            'name' => 'PDF Workspace',
            'vault_path' => $vaultPath,
        ]);
    }
}
