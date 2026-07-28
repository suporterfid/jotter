<?php

namespace Tests\Feature;

use App\Domain\Vault\VaultStorage;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DailyNotesAndTemplatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_template_substitution_and_idempotent_daily_note_creation(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $tenant = Tenant::create(['slug' => 'test', 'name' => 'Test']);

        $vaultPath = storage_path('app/vaults/tmpl_test_'.uniqid());
        mkdir($vaultPath, 0755, true);

        $workspace = Workspace::create([
            'tenant_id' => $tenant->id,
            'slug' => 'main',
            'name' => 'Main Workspace',
            'vault_path' => $vaultPath,
        ]);

        /** @var VaultStorage $storage */
        $storage = $this->app->make(VaultStorage::class);

        // 1. Create a template note in _templates/meeting.md
        $storage->write($workspace, '_templates/meeting.md', "---\ntitle: \"{{title}}\"\n---\n# Meeting: {{title}}\n\nWorkspace: {{workspace}}");

        // 2. Create note from template
        $fromTmplRes = $this->actingAs($admin)->postJson("/api/workspaces/{$workspace->id}/notes/from-template", [
            'template_path' => '_templates/meeting.md',
            'target_path' => 'meetings/standup.md',
            'title' => 'Project Standup',
        ]);

        $fromTmplRes->assertCreated()
            ->assertJsonPath('data.path', 'meetings/standup.md');

        $this->assertFileExists($vaultPath.'/meetings/standup.md');
        $this->assertStringContainsString('Meeting: Project Standup', file_get_contents($vaultPath.'/meetings/standup.md'));
        $this->assertStringContainsString('Workspace: Main Workspace', file_get_contents($vaultPath.'/meetings/standup.md'));

        // 3. Create Daily Note for date 2026-07-28
        $dailyRes1 = $this->actingAs($admin)->getJson("/api/workspaces/{$workspace->id}/daily/2026-07-28");
        $dailyRes1->assertCreated()
            ->assertJsonPath('data.path', 'journal/2026/07/2026-07-28.md');

        $this->assertFileExists($vaultPath.'/journal/2026/07/2026-07-28.md');

        // 4. Idempotent call returns same existing daily note
        $dailyRes2 = $this->actingAs($admin)->getJson("/api/workspaces/{$workspace->id}/daily/2026-07-28");
        $dailyRes2->assertOk()
            ->assertJsonPath('data.id', $dailyRes1->json('data.id'));
    }
}
