<?php

namespace Tests\Feature;

use App\Domain\Vault\VaultStorage;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class WikilinkResolutionPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_case_insensitive_wikilink_resolution(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $tenant = Tenant::create(['slug' => 'default', 'name' => 'Default']);
        $vaultPath = storage_path('app/vaults/wikilink_policy_test');
        @mkdir($vaultPath, 0755, true);

        $workspace = Workspace::create([
            'tenant_id' => $tenant->id,
            'slug' => 'main',
            'name' => 'Main',
            'vault_path' => $vaultPath,
        ]);

        $storage = app(VaultStorage::class);
        $targetNote = $storage->write($workspace, 'Welcome.md', "# Welcome Page\n");

        $sourceNote = $storage->write($workspace, 'index.md', "# Home\n\nLink to [[welcome]] or [[WELCOME.MD]].\n");

        $links = $sourceNote->outgoingLinks;

        $this->assertNotEmpty($links);
        foreach ($links as $link) {
            $this->assertEquals($targetNote->id, $link->target_note_id);
        }
    }
}
