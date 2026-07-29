<?php

namespace Tests\Feature;

use App\Domain\Vault\VaultStorage;
use App\Models\NoteComment;
use App\Models\Notification;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MilestoneCFinalTest extends TestCase
{
    use RefreshDatabase;

    public function test_comments_mentions_notifications_and_collections(): void
    {
        $admin = User::factory()->create(['name' => 'Alice Admin', 'email' => 'alice@example.com', 'is_admin' => true]);
        $mentionedUser = User::factory()->create(['name' => 'Bob Mentioned', 'email' => 'bob@example.com', 'is_admin' => false]);

        $tenant = Tenant::create(['slug' => 'test', 'name' => 'Test']);
        $vaultPath = storage_path('app/vaults/m_c_test_'.uniqid());
        mkdir($vaultPath, 0755, true);

        $workspace = Workspace::create([
            'tenant_id' => $tenant->id,
            'slug' => 'main',
            'name' => 'Main Workspace',
            'vault_path' => $vaultPath,
        ]);

        $workspace->memberships()->create([
            'tenant_id' => $tenant->id,
            'subject_id' => (string) $mentionedUser->id,
            'user_id' => $mentionedUser->id,
            'role' => 'member',
        ]);

        /** @var VaultStorage $storage */
        $storage = $this->app->make(VaultStorage::class);
        $note = $storage->write($workspace, 'project.md', "---\nstatus: active\n---\n# Project Note\nContent");

        // 1. Post comment mentioning @bob@example.com
        $commentRes = $this->actingAs($admin)->postJson("/api/workspaces/{$workspace->id}/notes/{$note->id}/comments", [
            'content' => 'Hey @bob@example.com please review this note.',
            'anchor_line' => 3,
        ]);

        $commentRes->assertCreated()
            ->assertJsonPath('data.content', 'Hey @bob@example.com please review this note.');

        $this->assertDatabaseHas('note_comments', [
            'workspace_id' => $workspace->id,
            'note_id' => $note->id,
        ]);

        // 2. Mention notification produced for Bob
        $this->assertDatabaseHas('notifications', [
            'workspace_id' => $workspace->id,
            'user_id' => $mentionedUser->id,
            'type' => 'mention',
        ]);

        // 3. Bob lists notifications
        $notifList = $this->actingAs($mentionedUser)->getJson("/api/workspaces/{$workspace->id}/notifications");
        $notifList->assertOk()->assertJsonCount(1, 'data');
        $notifId = $notifList->json('data.0.id');

        // 4. Bob marks notification read
        $markRead = $this->actingAs($mentionedUser)->postJson("/api/workspaces/{$workspace->id}/notifications/{$notifId}/read");
        $markRead->assertOk()->assertJsonPath('data.read_at', fn ($val) => $val !== null);

        // 5. Test Collections Table View
        $collectionRes = $this->actingAs($admin)->getJson("/api/workspaces/{$workspace->id}/collections?property=status&value=active");
        $collectionRes->assertOk()->assertJsonPath('total', 1);
    }

    public function test_collections_filter_matches_non_string_property_types(): void
    {
        // Regression: filtering only ever compared against value_string, so a
        // filter on a numeric/boolean/datetime property (whose value lives in
        // a different column) could never match — the previous test only
        // ever used a string property ("status"), which coincidentally
        // masked this for every other type.
        $admin = User::factory()->create(['is_admin' => true]);
        $tenant = Tenant::create(['slug' => 'test-numeric', 'name' => 'Test Numeric']);
        $vaultPath = storage_path('app/vaults/m_c_numeric_'.uniqid());
        mkdir($vaultPath, 0755, true);

        $workspace = Workspace::create([
            'tenant_id' => $tenant->id,
            'slug' => 'numeric',
            'name' => 'Numeric Workspace',
            'vault_path' => $vaultPath,
        ]);

        /** @var VaultStorage $storage */
        $storage = $this->app->make(VaultStorage::class);
        $storage->write($workspace, 'high.md', "---\npriority: 5\n---\n# High Priority");
        $storage->write($workspace, 'low.md', "---\npriority: 1\n---\n# Low Priority");

        $response = $this->actingAs($admin)
            ->getJson("/api/workspaces/{$workspace->id}/collections?property=priority&value=5");

        $response->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.title', 'High Priority');
    }
}
