<?php

namespace Tests\Feature\GitHubVault;

use App\Domain\Provisioning\TenantProvisioner;
use App\Domain\Vault\AttachmentStorage;
use App\Domain\Vault\Move\VaultMoveRefused;
use App\Domain\Vault\Move\VaultMover;
use App\Domain\Vault\Store\GitHub\GitHubFlusher;
use App\Domain\Vault\Store\GitHub\GitHubGit;
use App\Domain\Vault\Store\GitHub\GitHubSync;
use App\Domain\Vault\VaultStorage;
use App\Models\Note;
use App\Models\Tenant;
use App\Models\TenantVaultStore;
use App\Models\VaultJournalEntry;
use App\Models\VaultMove;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Support\InteractsWithFakeGitHub;
use Tests\TestCase;

/** vault:move local ↔ GitHub (#443), the first remote driver to use the engine (G4). */
class GitHubMoveTest extends TestCase
{
    use InteractsWithFakeGitHub;
    use RefreshDatabase;

    private Tenant $tenant;

    private Workspace $workspace;

    private TenantVaultStore $store;

    /** @var array<string, string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFakeGitHub();
        $this->github->createRepo('acme/vault');

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);
        $this->workspace = Workspace::create([
            'tenant_id' => $this->tenant->id, 'slug' => 'default', 'name' => 'Default',
            'vault_path' => storage_path('app/vaults/github-move-'.uniqid()),
        ]);
        $storage = app(VaultStorage::class);
        foreach (['Home.md' => "# Home\n\n[[Ideas/One]]\n", 'Ideas/One.md' => "# One ção\n", 'Ideas/Two.md' => "# Two\n"] as $path => $body) {
            $storage->write($this->workspace, $path, $body);
            $this->files[$path] = $body;
        }
        $png = "\x89PNG\r\n\x1a\n".random_bytes(1024);
        app(AttachmentStorage::class)->writeAttachment($this->workspace, $png, '_resources/pic.png', 'image/png');
        $this->files['_resources/pic.png'] = $png;
        ksort($this->files, SORT_STRING);

        $this->store = $this->patStore($this->tenant, 'acme/vault');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory((string) $this->workspace->vault_path);
        parent::tearDown();
    }

    public function test_the_default_vault_goes_to_github_and_back_without_losing_a_file(): void
    {
        $mover = app(VaultMover::class);

        $move = $mover->runToCompletion($mover->start($this->workspace, $this->store, 'admin'), 2);

        $this->assertSame(VaultMove::COMPLETED, $move->status);
        $this->assertSame([], $this->github->log('acme/vault'), 'The probe and the copy never committed by themselves.');
        app(GitHubFlusher::class)->flush($this->store);
        $this->assertSame($this->files, $this->github->files('acme/vault'));
        foreach ($this->files as $path => $bytes) {
            $this->assertSame(GitHubGit::blobSha($bytes), Note::query()->where('path', $path)->value('remote_version') ?? \App\Models\Attachment::query()->where('path', $path)->value('remote_version'), $path);
        }
        // An empty repository takes its first file through the contents API, the rest in one batch.
        $log = $this->github->log('acme/vault');
        $this->assertCount(2, $log);
        $this->assertSame(['jotter:', 'jotter:'], array_map(fn ($m) => substr($m, 0, 7), $log), 'Every commit is the move; no probe commit.');

        // And back to the local folder.
        $back = $mover->runToCompletion($mover->start($this->workspace->fresh(), null, 'admin'), 2);
        $this->assertSame(VaultMove::COMPLETED, $back->status);
        $local = app(\App\Domain\Vault\Store\VaultStoreResolver::class)->for($this->workspace->fresh());
        foreach ($this->files as $path => $bytes) {
            $this->assertSame(hash('sha256', $bytes), hash('sha256', $local->read($path)), $path);
        }
    }

    public function test_the_provisioned_default_workspace_moves_to_github(): void
    {
        $provisioned = app(TenantProvisioner::class)->provision(new \App\Domain\Provisioning\ProvisioningRequest(
            'Globex', 'globex', 'Globex', 'globex', 'admin@globex.test', 'Admin', sendWelcomeEmail: false,
        ));
        $workspace = $provisioned->workspace;
        app(VaultStorage::class)->write($workspace, 'Welcome.md', "# Welcome\n");
        $this->github->createRepo('globex/vault');
        $store = $this->patStore($provisioned->tenant, 'globex/vault', token: 'pat-globex');

        $mover = app(VaultMover::class);
        $move = $mover->runToCompletion($mover->start($workspace, $store), 50);
        app(GitHubFlusher::class)->flush($store);

        $this->assertSame(VaultMove::COMPLETED, $move->status);
        $local = app(\App\Domain\Vault\Store\VaultStoreResolver::class)->local($workspace);
        $expected = [];
        $cursor = null;
        do {
            $page = $local->list('', $cursor, 100);
            foreach ($page->entries as $entry) {
                $expected[$entry->path] = $local->read($entry->path);
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
        ksort($expected, SORT_STRING);
        $this->assertSame($expected, $this->github->files('globex/vault'));
        File::deleteDirectory((string) $workspace->vault_path);
    }

    public function test_an_interrupted_copy_resumes_without_duplicating_commits_or_files(): void
    {
        $mover = app(VaultMover::class);
        $move = $mover->start($this->workspace, $this->store);
        $move = $mover->process($move, 2);
        $this->assertSame(VaultMove::COPYING, $move->status);

        // The cron dies; the next run picks up from the cursor.
        $move = $mover->runToCompletion(VaultMove::query()->findOrFail($move->id), 1);
        app(GitHubFlusher::class)->flush($this->store);

        $this->assertSame(VaultMove::COMPLETED, $move->status);
        $this->assertSame($this->files, $this->github->files('acme/vault'));
        $this->assertCount(2, $this->github->log('acme/vault'), 'Bootstrap commit + one batch: nothing duplicated.');
        $this->assertSame(4, VaultJournalEntry::query()->count());
    }

    public function test_an_exhausted_quota_during_the_move_only_delays_the_commits(): void
    {
        config(['jotter.github.writes_per_minute' => 3]);
        $mover = app(VaultMover::class);
        $move = $mover->runToCompletion($mover->start($this->workspace, $this->store), 10);

        $this->assertSame(VaultMove::COMPLETED, $move->status);
        $this->assertSame('delayed', app(GitHubFlusher::class)->flush($this->store)['status']);
        $this->assertSame("# Two\n", app(VaultStorage::class)->readContents($this->workspace->fresh(), 'Ideas/Two.md'), 'The workspace works from the journal meanwhile.');

        config(['jotter.github.writes_per_minute' => 60]);
        $this->travel(5)->minutes();
        app(GitHubFlusher::class)->flush($this->store);
        $this->assertSame($this->files, $this->github->files('acme/vault'));
    }

    public function test_an_external_push_during_the_move_is_kept_and_indexed(): void
    {
        $mover = app(VaultMover::class);
        $move = $mover->start($this->workspace, $this->store);
        $move = $mover->process($move, 2);
        $this->github->push('acme/vault', ['From laptop.md' => "# From laptop\n"]);
        app(GitHubSync::class)->pull($this->store);

        $move = $mover->runToCompletion($move, 10);
        app(GitHubFlusher::class)->flush($this->store);

        $this->assertSame(VaultMove::COMPLETED, $move->status);
        $expected = $this->files + ['From laptop.md' => "# From laptop\n"];
        ksort($expected, SORT_STRING);
        $this->assertSame($expected, $this->github->files('acme/vault'));
        $this->assertNotNull(Note::query()->where('workspace_id', $this->workspace->id)->where('path', 'From laptop.md')->first());
    }

    public function test_a_repository_that_is_not_empty_or_already_used_is_refused(): void
    {
        $this->github->createRepo('acme/busy', ['README.md' => "# hi\n"]);
        $busy = $this->patStore($this->tenant, 'acme/busy', token: 'pat-busy');
        try {
            app(VaultMover::class)->start($this->workspace, $busy);
            $this->fail('A non-empty repository must be refused.');
        } catch (VaultMoveRefused $refused) {
            $this->assertStringContainsString('not empty', $refused->getMessage());
        }

        $other = Workspace::create(['tenant_id' => $this->tenant->id, 'slug' => 'other', 'name' => 'Other', 'vault_path' => storage_path('app/vaults/github-move-other-'.uniqid())]);
        $other->forceFill(['vault_store_id' => $this->store->id])->save();
        $this->expectException(VaultMoveRefused::class);
        $this->expectExceptionMessage('another workspace');
        app(VaultMover::class)->start($this->workspace, $this->store);
    }

    public function test_cli_move_to_github_then_flush(): void
    {
        $this->artisan('vault:move', ['workspace' => $this->workspace->id, '--to' => $this->store->id, '--sync' => true])->assertSuccessful();
        $this->artisan('vault:flush')->assertSuccessful();

        $this->assertSame($this->files, $this->github->files('acme/vault'));
        $this->assertSame((int) $this->store->id, (int) $this->workspace->fresh()->vault_store_id);
    }
}
