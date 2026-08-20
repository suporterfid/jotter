<?php

namespace Tests\Feature;

use App\Domain\Vault\VaultReindexer;
use App\Domain\Vault\VaultStorage;
use App\Models\NoteAclEntry;
use App\Models\NoteRevision;
use App\Models\Membership;
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
        $this->assertDatabaseHas('note_revisions', [
            'note_id' => $note->id,
            'content_hash' => hash('sha256', "# Version 1\nFirst version content."),
            'actor_id' => (string) $admin->id,
        ]);

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

    public function test_revisions_can_be_compared_with_current_content_and_acl_isolation(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $reader = User::factory()->create();
        $tenant = Tenant::create(['slug' => 'compare-'.uniqid(), 'name' => 'Compare']);

        $vaultPath = storage_path('app/vaults/rev_compare_'.uniqid());
        mkdir($vaultPath, 0755, true);

        $workspace = Workspace::create([
            'tenant_id' => $tenant->id,
            'slug' => 'compare',
            'name' => 'Compare Workspace',
            'vault_path' => $vaultPath,
        ]);
        Membership::create([
            'subject_id' => (string) $reader->id,
            'tenant_id' => $tenant->id,
            'workspace_id' => $workspace->id,
            'role' => 'viewer',
        ]);

        /** @var VaultStorage $storage */
        $storage = $this->app->make(VaultStorage::class);
        $note = $storage->write($workspace, 'compare.md', "same\nold");
        $first = NoteRevision::query()->where('note_id', $note->id)->firstOrFail();
        $storage->write($workspace, 'compare.md', "same\nnew");
        $second = NoteRevision::query()->where('note_id', $note->id)->latest('id')->firstOrFail();

        $response = $this->actingAs($admin)->getJson(
            "/api/workspaces/{$workspace->id}/notes/{$note->id}/revisions/compare?from={$first->id}&to={$second->id}"
        );

        $response->assertOk()
            ->assertJsonPath('data.from.id', $first->id)
            ->assertJsonPath('data.to.id', $second->id)
            ->assertJsonPath('data.changed', true)
            ->assertJsonFragment(['type' => 'removed', 'text' => 'old'])
            ->assertJsonFragment(['type' => 'added', 'text' => 'new']);

        $storage->write($workspace, 'compare.md', "same\ncurrent");
        $currentResponse = $this->actingAs($admin)->getJson(
            "/api/workspaces/{$workspace->id}/notes/{$note->id}/revisions/compare?from={$second->id}&to=current"
        );
        $currentResponse->assertOk()
            ->assertJsonPath('data.to.id', null)
            ->assertJsonPath('data.to.content_hash', hash('sha256', "same\ncurrent"))
            ->assertJsonFragment(['type' => 'removed', 'text' => 'new'])
            ->assertJsonFragment(['type' => 'added', 'text' => 'current']);

        $sameResponse = $this->actingAs($admin)->getJson(
            "/api/workspaces/{$workspace->id}/notes/{$note->id}/revisions/compare?from={$second->id}&to={$second->id}"
        );
        $sameResponse->assertOk()->assertJsonPath('data.changed', false)->assertJsonPath('data.lines', []);

        NoteAclEntry::create([
            'note_id' => $note->id,
            'principal_type' => 'user',
            'principal_id' => $admin->id,
            'permission' => 'view',
        ]);
        $this->actingAs($reader)->getJson(
            "/api/workspaces/{$workspace->id}/notes/{$note->id}/revisions/compare?from={$first->id}&to={$second->id}"
        )->assertNotFound();

        $this->actingAs($admin)->getJson(
            "/api/workspaces/{$workspace->id}/notes/{$note->id}/revisions/compare?from={$first->id}&to=999999"
        )->assertNotFound();

        $otherVaultPath = storage_path('app/vaults/rev_compare_other_'.uniqid());
        mkdir($otherVaultPath, 0755, true);
        $otherWorkspace = Workspace::create([
            'tenant_id' => $tenant->id,
            'slug' => 'compare-other',
            'name' => 'Other Compare Workspace',
            'vault_path' => $otherVaultPath,
        ]);
        $otherNote = $storage->write($otherWorkspace, 'other.md', 'other');
        $otherRevision = NoteRevision::query()->where('note_id', $otherNote->id)->firstOrFail();

        $this->actingAs($admin)->getJson(
            "/api/workspaces/{$workspace->id}/notes/{$note->id}/revisions/compare?from={$otherRevision->id}&to={$second->id}"
        )->assertNotFound();
        $this->actingAs($admin)->getJson(
            "/api/workspaces/{$workspace->id}/notes/{$note->id}/revisions/compare?from={$first->id}&to={$otherRevision->id}"
        )->assertNotFound();

        $stranger = User::factory()->create();
        $this->actingAs($stranger)->getJson(
            "/api/workspaces/{$workspace->id}/notes/{$note->id}/revisions/compare?from={$first->id}&to={$second->id}"
        )->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->getJson(
            "/api/workspaces/{$workspace->id}/notes/{$note->id}/revisions/compare?from={$first->id}&to={$second->id}"
        )->assertUnauthorized();
        $this->actingAs($admin)->getJson(
            "/api/workspaces/{$workspace->id}/notes/{$note->id}/revisions/compare?to={$second->id}"
        )->assertStatus(422);
        $this->actingAs($admin)->getJson(
            "/api/workspaces/{$workspace->id}/notes/{$note->id}/revisions/compare?from={$first->id}&to=invalid"
        )->assertStatus(422);
    }
}
