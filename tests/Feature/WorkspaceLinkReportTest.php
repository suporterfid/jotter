<?php

namespace Tests\Feature;

use App\Domain\Vault\VaultReindexer;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class WorkspaceLinkReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_link_report_returns_broken_links_and_orphans(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $tenant = Tenant::create(['slug' => 'test', 'name' => 'Test']);

        $vaultPath = storage_path('app/vaults/link_report_'.uniqid());
        mkdir($vaultPath, 0755, true);

        $workspace = Workspace::create([
            'tenant_id' => $tenant->id,
            'slug' => 'main',
            'name' => 'Main',
            'vault_path' => $vaultPath,
        ]);

        // 1. Note A points to missing note [[non-existent-page]]
        file_put_contents($vaultPath.'/welcome.md', "# Welcome\n\nLink to [[non-existent-page]]");
        // 2. Note B is an orphan note with no links
        file_put_contents($vaultPath.'/orphan.md', "# Orphan Note");

        /** @var VaultReindexer $reindexer */
        $reindexer = $this->app->make(VaultReindexer::class);
        $reindexer->reindex($workspace);

        $response = $this->actingAs($admin)->getJson("/api/workspaces/{$workspace->id}/link-report");
        $response->assertOk()
            ->assertJsonCount(1, 'broken_links')
            ->assertJsonPath('broken_links.0.target_ref', 'non-existent-page')
            ->assertJsonCount(2, 'orphans'); // welcome.md and orphan.md have no inbound links

        // 3. Resolve broken link by creating non-existent-page.md
        file_put_contents($vaultPath.'/non-existent-page.md', "# Non Existent Page");
        $reindexer->reindex($workspace);

        $responseResolved = $this->actingAs($admin)->getJson("/api/workspaces/{$workspace->id}/link-report");
        $responseResolved->assertOk()
            ->assertJsonCount(0, 'broken_links');
    }
}
