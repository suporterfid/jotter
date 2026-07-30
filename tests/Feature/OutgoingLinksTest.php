<?php

namespace Tests\Feature;

use App\Domain\Vault\VaultStorage;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class OutgoingLinksTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_outgoing_links_including_resolved_target_and_block_reference(): void
    {
        $workspace = $this->makeWorkspace();
        $storage = new VaultStorage;

        $target = $storage->write($workspace, 'note-b.md', "Paragraph.\n^myblock\n");
        $source = $storage->write(
            $workspace,
            'note-a.md',
            "See [[note-b]] and [[note-b#^myblock]] and [[missing-note]].\n",
        );

        $admin = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($admin)
            ->getJson("/api/workspaces/{$workspace->id}/notes/{$source->id}/outgoing-links");

        $response->assertOk()->assertJsonCount(3, 'data');

        $byRef = collect($response->json('data'))->keyBy('target_ref');

        $this->assertSame($target->id, $byRef['note-b']['id']);
        $this->assertTrue($byRef['note-b']['resolved']);
        $this->assertNull($byRef['note-b']['target_block']);

        $this->assertSame($target->id, $byRef['note-b#^myblock']['id']);
        $this->assertSame('myblock', $byRef['note-b#^myblock']['target_block']);

        $this->assertFalse($byRef['missing-note']['resolved']);
        $this->assertNull($byRef['missing-note']['id']);
    }

    public function test_outgoing_links_endpoint_requires_authentication(): void
    {
        $workspace = $this->makeWorkspace();
        $storage = new VaultStorage;
        $source = $storage->write($workspace, 'note-a.md', "No links here.\n");

        $response = $this->getJson("/api/workspaces/{$workspace->id}/notes/{$source->id}/outgoing-links");

        $response->assertUnauthorized();
    }

    private function makeWorkspace(): Workspace
    {
        $tenant = Tenant::query()->create([
            'slug' => 'outgoing-tenant-'.uniqid(),
            'name' => 'Outgoing Links Tenant',
        ]);

        $vaultPath = storage_path('app/vaults/outgoing_'.uniqid());
        mkdir($vaultPath, 0755, true);

        return Workspace::query()->create([
            'tenant_id' => $tenant->id,
            'slug' => 'outgoing-ws-'.uniqid(),
            'name' => 'Outgoing Links Workspace',
            'vault_path' => $vaultPath,
        ]);
    }
}
