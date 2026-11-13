<?php

namespace Tests\Feature\VaultStore;

use App\Domain\Auth\MachineTokenIssuer;
use App\Domain\Auth\MachineTokenOptions;
use App\Domain\Vault\AttachmentStorage;
use App\Domain\Vault\Move\VaultMoveRefused;
use App\Domain\Vault\Move\VaultMover;
use App\Domain\Vault\Store\VaultStoreResolver;
use App\Domain\Vault\VaultStorage;
use App\Models\Membership;
use App\Models\Note;
use App\Models\Tenant;
use App\Models\TenantVaultStore;
use App\Models\User;
use App\Models\VaultMove;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * vault:move (#437) with two LocalVaultStores as source and destination: the
 * engine never knows the driver, so this covers the generic flow.
 */
class VaultMoveTest extends TestCase
{
    use RefreshDatabase;

    private string $base;

    private Tenant $tenant;

    private Workspace $workspace;

    private TenantVaultStore $diskB;

    /** @var array<string, string> */
    private array $files;

    protected function setUp(): void
    {
        parent::setUp();
        config(['jotter.vault_stores.drivers' => ['local', 's3'], 'jotter.vault_stores.move_retain_days' => 7]);

        $this->base = storage_path('app/vaults/move-tests-'.uniqid());
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);
        $this->workspace = Workspace::create(['tenant_id' => $this->tenant->id, 'slug' => 'docs', 'name' => 'Docs', 'vault_path' => $this->base.'/docs']);
        $this->diskB = TenantVaultStore::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Disk B', 'driver' => 'local',
            'settings' => ['root' => $this->base.'/disk-b'], 'status' => 'ok',
        ]);

        $storage = app(VaultStorage::class);
        $this->files = [];
        foreach (['a.md' => "# A\n\n[[folder/b]]\n", 'folder/b.md' => "# B\n", 'folder/deep/c.md' => "# C ção\n", 'd.md' => "# D\n", 'e.md' => "# E\n"] as $path => $body) {
            $storage->write($this->workspace, $path, $body);
            $this->files[$path] = $body;
        }
        $png = "\x89PNG\r\n\x1a\n".random_bytes(2048);
        app(AttachmentStorage::class)->writeAttachment($this->workspace, $png, '_resources/img.png', 'image/png');
        $this->files['_resources/img.png'] = $png;
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->base);

        parent::tearDown();
    }

    public function test_workspace_moves_to_another_store_and_back_without_losing_a_file(): void
    {
        $mover = app(VaultMover::class);

        $move = $mover->runToCompletion($mover->start($this->workspace, $this->diskB, 'tester'), 2);

        $this->assertSame(VaultMove::COMPLETED, $move->status);
        $this->assertSame(6, $move->files_copied);
        $this->workspace->refresh();
        $this->assertSame($this->diskB->id, $this->workspace->vault_store_id);
        $this->assertSame('active', $this->workspace->storageState());
        $this->assertNotNull($move->retain_until);
        $this->assertTrue($move->retain_until->between(now()->addDays(6), now()->addDays(8)));

        $store = app(VaultStoreResolver::class)->for($this->workspace);
        foreach ($this->files as $path => $bytes) {
            $this->assertSame($bytes, $store->read($path), "{$path} differs after the move");
            $this->assertFileExists($this->base.'/docs/'.$path, 'The source is kept for rollback.');
        }
        $this->assertSame(hash('sha256', $this->files['a.md']), Note::query()->where('path', 'a.md')->value('remote_version'));

        // Edits now land in the destination.
        app(VaultStorage::class)->write($this->workspace, 'a.md', "# A edited\n");
        $this->assertSame("# A edited\n", file_get_contents($this->base.'/disk-b/docs-'.$this->workspace->id.'/a.md'));
        $this->assertSame($this->files['a.md'], file_get_contents($this->base.'/docs/a.md'));
        $this->files['a.md'] = "# A edited\n";

        // And back to the local folder: the stale retained copy is replaced.
        $back = $mover->runToCompletion($mover->start($this->workspace->fresh(), null, 'tester'));
        $this->assertSame(VaultMove::COMPLETED, $back->status);
        $this->assertSame(VaultMove::FINALIZED, $move->fresh()->status);
        $this->workspace->refresh();
        $this->assertNull($this->workspace->vault_store_id);
        foreach ($this->files as $path => $bytes) {
            $this->assertSame($bytes, file_get_contents($this->base.'/docs/'.$path), "{$path} differs after moving back");
        }

        $this->assertDatabaseHas('audit_log', ['event' => 'vault.move_started']);
        $this->assertDatabaseHas('audit_log', ['event' => 'vault.move_completed']);
        $this->assertDatabaseHas('audit_log', ['event' => 'vault.move_finalized']);
    }

    public function test_an_interrupted_copy_resumes_from_the_cursor(): void
    {
        $mover = app(VaultMover::class);
        $move = $mover->start($this->workspace, $this->diskB);

        $move = $mover->process($move, 2);
        $this->assertSame(VaultMove::COPYING, $move->status);
        $this->assertSame(2, $move->files_copied);
        $cursor = $move->cursor;
        $this->assertNotNull($cursor);

        // The process dies here; the next cron run picks the move up again.
        $this->artisan('vault:process-moves', ['--limit' => 3])->assertSuccessful();
        $move->refresh();
        $this->assertSame(5, $move->files_copied);
        $this->assertGreaterThan($cursor, $move->cursor);

        $this->artisan('vault:process-moves')->assertSuccessful();
        $move->refresh();
        $this->assertSame(VaultMove::COMPLETED, $move->status);
        $this->assertSame(6, $move->files_copied);
        $this->assertSame(count($this->files), count(File::allFiles($this->base.'/disk-b')));
    }

    public function test_writes_answer_423_during_the_move_and_reads_keep_working(): void
    {
        config(['jotter.auth_bypass' => false, 'jotter.auth_provider' => 'local']);
        $editor = User::factory()->create(['is_admin' => false]);
        Membership::create(['tenant_id' => $this->tenant->id, 'workspace_id' => $this->workspace->id, 'subject_id' => (string) $editor->id, 'role' => 'editor']);
        $note = Note::query()->where('path', 'd.md')->firstOrFail();

        app(VaultMover::class)->start($this->workspace, $this->diskB);

        // REST API
        $this->actingAs($editor)->putJson("/api/workspaces/{$this->workspace->id}/notes/{$note->id}", ['content' => '# nope'])
            ->assertStatus(423)->assertJsonPath('storage_state', 'moving');
        $this->actingAs($editor)->getJson("/api/workspaces/{$this->workspace->id}/notes/{$note->id}")->assertOk();

        // WebDAV
        $this->actingAs($editor)->call('PUT', "/api/webdav/{$this->workspace->id}/d.md", [], [], [], [], '# nope')->assertStatus(423);
        $this->actingAs($editor)->call('DELETE', "/api/webdav/{$this->workspace->id}/d.md")->assertStatus(423);
        $this->actingAs($editor)->call('GET', "/api/webdav/{$this->workspace->id}/d.md")->assertOk();

        // MCP write tools
        $options = MachineTokenOptions::fromInput($editor, $this->tenant, ['mcp:read', 'mcp:write'], null, ['inbox/{client}/**:create,append,update'], 'hermes');
        $plain = app(MachineTokenIssuer::class)->issue($editor, $this->tenant, 'writer', $options)['plain'];
        $this->app['auth']->forgetGuards();
        $this->withToken($plain)->postJson('/api/mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'create_note', 'arguments' => ['path' => 'inbox/hermes/x.md', 'content' => 'x']],
        ])->assertOk()->assertJsonPath('result.isError', true);

        // Domain layer, for any other caller.
        $this->expectException(\App\Domain\Vault\Store\WorkspaceStorageMoving::class);
        app(VaultStorage::class)->write($this->workspace, 'd.md', 'x');
    }

    public function test_preflight_refuses_a_non_empty_destination_and_a_second_move(): void
    {
        $mover = app(VaultMover::class);
        File::ensureDirectoryExists($this->base.'/disk-b/docs-'.$this->workspace->id);
        file_put_contents($this->base.'/disk-b/docs-'.$this->workspace->id.'/stray.md', 'x');

        try {
            $mover->start($this->workspace, $this->diskB);
            $this->fail('A non-empty destination must be refused.');
        } catch (VaultMoveRefused $refused) {
            $this->assertStringContainsString('not empty', $refused->getMessage());
        }
        $this->assertSame('active', $this->workspace->fresh()->storageState());

        File::deleteDirectory($this->base.'/disk-b');
        $mover->start($this->workspace, $this->diskB);
        $this->expectException(VaultMoveRefused::class);
        $mover->start($this->workspace, $this->diskB);
    }

    public function test_a_failed_copy_leaves_the_source_writable_and_cleans_the_destination(): void
    {
        $mover = app(VaultMover::class);
        $move = $mover->start($this->workspace, $this->diskB);
        $move = $mover->process($move, 1);

        // The destination store disappears mid-move (deleted folder replaced by a file).
        File::deleteDirectory($this->base.'/disk-b');
        file_put_contents($this->base.'/disk-b', 'not a directory');

        $move = $mover->process($move, 10);
        $this->assertSame(VaultMove::FAILED, $move->status);
        $this->assertNotNull($move->error);
        $this->workspace->refresh();
        $this->assertSame('active', $this->workspace->storageState());
        $this->assertNull($this->workspace->vault_store_id);
        app(VaultStorage::class)->write($this->workspace, 'd.md', "# still writable\n");
        $this->assertDatabaseHas('audit_log', ['event' => 'vault.move_failed']);
    }

    public function test_rollback_returns_to_the_retained_source_and_refuses_after_edits_unless_forced(): void
    {
        $mover = app(VaultMover::class);
        $move = $mover->runToCompletion($mover->start($this->workspace, $this->diskB));

        $rolledBack = $mover->rollback($move, 'tester');
        $this->assertSame(VaultMove::ROLLED_BACK, $rolledBack->status);
        $this->assertNull($this->workspace->fresh()->vault_store_id);
        $this->assertDirectoryDoesNotExist($this->base.'/disk-b/docs-'.$this->workspace->id);
        $this->assertDatabaseHas('audit_log', ['event' => 'vault.move_rolled_back']);

        $second = $mover->runToCompletion($mover->start($this->workspace->fresh(), $this->diskB));
        $this->travel(5)->seconds();
        app(VaultStorage::class)->write($this->workspace->fresh(), 'd.md', "# changed after the move\n");

        try {
            $mover->rollback($second);
            $this->fail('Rolling back over newer edits must be refused.');
        } catch (VaultMoveRefused $refused) {
            $this->assertStringContainsString('--force', $refused->getMessage());
        }

        $mover->rollback($second, 'tester', force: true);
        $this->assertNull($this->workspace->fresh()->vault_store_id);
        $this->assertFileExists($this->base.'/disk-b/docs-'.$this->workspace->id.'/d.md', 'With --force the destination keeps the newer edits.');
    }

    public function test_finalize_deletes_the_source_only_after_retention(): void
    {
        $mover = app(VaultMover::class);
        $move = $mover->runToCompletion($mover->start($this->workspace, $this->diskB));

        $this->artisan('vault:finalize-moves')->assertSuccessful();
        $this->assertSame(VaultMove::COMPLETED, $move->fresh()->status);
        $this->assertFileExists($this->base.'/docs/a.md');

        $this->travel(8)->days();
        $this->artisan('vault:finalize-moves')->assertSuccessful();
        $this->assertSame(VaultMove::FINALIZED, $move->fresh()->status);
        $this->assertDirectoryDoesNotExist($this->base.'/docs');

        $this->expectException(VaultMoveRefused::class);
        $mover->rollback($move->fresh());
    }

    public function test_cli_moves_synchronously_and_admin_api_reports_progress(): void
    {
        $this->artisan('vault:move', ['workspace' => 'docs', '--to' => $this->diskB->id, '--sync' => true])
            ->expectsOutputToContain('completed')
            ->assertSuccessful();
        $this->assertSame($this->diskB->id, $this->workspace->fresh()->vault_store_id);

        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->getJson("/api/admin/workspaces/{$this->workspace->id}/vault-moves")
            ->assertOk()
            ->assertJsonPath('data.workspace.vault_store_id', $this->diskB->id)
            ->assertJsonPath('data.moves.0.status', 'completed')
            ->assertJsonPath('data.moves.0.percent', 100);

        $this->actingAs($admin)->postJson("/api/admin/workspaces/{$this->workspace->id}/vault-moves", ['to_store_id' => $this->diskB->id])
            ->assertStatus(409);
        $this->actingAs($admin)->postJson("/api/admin/workspaces/{$this->workspace->id}/vault-moves", ['to_store_id' => null])
            ->assertStatus(202)->assertJsonPath('data.status', 'copying');

        $this->artisan('vault:move', ['workspace' => 'docs', '--local' => true])->assertFailed();
    }

    public function test_a_store_holding_a_retained_source_cannot_be_deleted(): void
    {
        $mover = app(VaultMover::class);
        $mover->runToCompletion($mover->start($this->workspace, $this->diskB));
        $back = $mover->runToCompletion($mover->start($this->workspace->fresh(), null));
        $this->assertSame(0, $this->diskB->workspaces()->count());

        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->deleteJson("/api/admin/vault-stores/{$this->diskB->id}")->assertStatus(409);

        $mover->finalize($back);
        $this->actingAs($admin)->deleteJson("/api/admin/vault-stores/{$this->diskB->id}")->assertOk();
        $this->assertNull($this->workspace->fresh()->vault_store_id);
        $this->assertSame($this->files['d.md'], file_get_contents($this->base.'/docs/d.md'));
    }
}
