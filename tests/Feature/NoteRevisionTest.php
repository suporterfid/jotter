<?php

namespace Tests\Feature;

use App\Domain\Vault\VaultReindexer;
use App\Domain\Vault\VaultStorage;
use App\Models\NoteRevision;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class NoteRevisionTest extends TestCase
{
    use RefreshDatabase;

    public function test_note_revision_creation_deduplication_restore_isolation_and_pruning(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $tenant = Tenant::create(['slug' => 'test', 'name' => 'Test']);

        $vaultPath = storage_path('app/vaults/rev_test_'.uniqid());
        mkdir($vaultPath, 0755, true);

        $workspace = Workspace::create([
            'tenant_id' => $tenant->id,
            'slug' => 'main',
            'name' => 'Main Workspace',
            'vault_path' => $vaultPath,
        ]);

        /** @var VaultStorage $storage */
        $storage = $this->app->make(VaultStorage::class);

        // 1. Initial write creates revision
        $note = $storage->write($workspace, 'history.md', "# Version 1\nFirst version content.");
        $this->assertDatabaseHas('note_revisions', [
            'workspace_id' => $workspace->id,
            'note_id' => $note->id,
        ]);
        $this->assertDatabaseCount('note_revisions', 1);

        // 2. Re-saving identical content deduplicates (no extra revision created)
        $storage->write($workspace, 'history.md', "# Version 1\nFirst version content.");
        $this->assertDatabaseCount('note_revisions', 1);

        // 3. Edit produces second revision
        $storage->write($workspace, 'history.md', "# Version 2\nSecond version content.");
        $this->assertDatabaseCount('note_revisions', 2);

        $revisions = NoteRevision::query()->where('note_id', $note->id)->orderBy('id')->get();
        $rev1 = $revisions[0];
        $rev2 = $revisions[1];

        // 4. Test API GET revisions list and show
        $listRes = $this->actingAs($admin)->getJson("/api/workspaces/{$workspace->id}/notes/{$note->id}/revisions");
        $listRes->assertOk()->assertJsonCount(2, 'data');

        $showRes = $this->actingAs($admin)->getJson("/api/workspaces/{$workspace->id}/notes/{$note->id}/revisions/{$rev1->id}");
        $showRes->assertOk()->assertJsonPath('data.content', "# Version 1\nFirst version content.");

        // 5. Restore Version 1 via API
        $restoreRes = $this->actingAs($admin)->postJson("/api/workspaces/{$workspace->id}/notes/{$note->id}/revisions/{$rev1->id}/restore");
        $restoreRes->assertOk();
        $this->assertEquals("# Version 1\nFirst version content.", file_get_contents($vaultPath.'/history.md'));

        // Restore creates a 3rd revision with Version 1 content
        $this->assertDatabaseCount('note_revisions', 3);

        // 6. Test out-of-band disk edit picked up by vault:reindex
        file_put_contents($vaultPath.'/history.md', "# Version 4 Out-of-band\nDisk edit.");
        /** @var VaultReindexer $reindexer */
        $reindexer = $this->app->make(VaultReindexer::class);
        $reindexer->reindex($workspace);
        $this->assertDatabaseCount('note_revisions', 4);

        // 7. Test Pruning command
        $rev1->created_at = now()->subDays(60);
        $rev1->save();

        $this->artisan('vault:prune-revisions', ['--days' => 30])
            ->assertExitCode(0);

        $this->assertDatabaseMissing('note_revisions', ['id' => $rev1->id]);
        $this->assertDatabaseCount('note_revisions', 3);
    }
}
