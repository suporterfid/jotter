<?php

namespace Tests\Feature\GitHubVault;

use App\Domain\Vault\Store\GitHub\GitHubFlusher;
use App\Domain\Vault\Store\GitHub\GitHubGit;
use App\Domain\Vault\Store\GitHub\GitHubSync;
use App\Domain\Vault\VaultStorage;
use App\Models\Note;
use App\Models\Tenant;
use App\Models\TenantVaultStore;
use App\Models\VaultJournalEntry;
use App\Models\VaultSyncState;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithFakeGitHub;
use Tests\TestCase;

/** Write-behind journal and vault:flush (#441), against the in-memory GitHub. */
class GitHubFlushTest extends TestCase
{
    use InteractsWithFakeGitHub;
    use RefreshDatabase;

    private Tenant $tenant;

    private TenantVaultStore $store;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFakeGitHub();
        $this->github->createRepo('acme/notes', ['README.md' => "# Notes\n"]);
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);
        $this->store = $this->patStore($this->tenant);
        $this->workspace = $this->workspaceOn($this->store);
    }

    public function test_a_save_is_instant_and_the_next_flush_commits_it(): void
    {
        $storage = app(VaultStorage::class);
        $note = $storage->write($this->workspace, 'ideas/plan.md', "# Plan\n\nShip it.\n");

        $this->assertSame(0, $this->github->writeCount(), 'Saving never calls GitHub.');
        $this->assertSame("# Plan\n\nShip it.\n", $storage->readContents($this->workspace, 'ideas/plan.md'));
        $this->assertSame(1, VaultJournalEntry::query()->whereNull('flushed_at')->count());

        $this->artisan('vault:flush')->assertSuccessful();

        $this->assertSame("# Plan\n\nShip it.\n", $this->github->files('acme/notes')['ideas/plan.md']);
        $this->assertSame(0, VaultJournalEntry::query()->whereNull('flushed_at')->count());
        $this->assertNull(VaultJournalEntry::query()->first()->content, 'Flushed bytes leave MySQL.');
        $this->assertSame(GitHubGit::blobSha("# Plan\n\nShip it.\n"), $note->fresh()->remote_version);
        $this->assertSame(3, $this->github->writeCount(), 'One batch = tree + commit + ref.');
        $this->assertSame("# Plan\n\nShip it.\n", $storage->readContents($this->workspace, 'ideas/plan.md'));
    }

    public function test_ten_edits_of_the_same_note_become_one_commit(): void
    {
        $storage = app(VaultStorage::class);
        for ($i = 1; $i <= 10; $i++) {
            $storage->write($this->workspace, 'draft.md', "# Draft\n\nversion {$i}\n");
        }
        $this->assertSame(1, VaultJournalEntry::query()->whereNull('flushed_at')->count(), 'Coalesced by path.');

        app(GitHubFlusher::class)->flush($this->store);

        $this->assertCount(2, $this->github->log('acme/notes'));
        $this->assertSame("# Draft\n\nversion 10\n", $this->github->files('acme/notes')['draft.md']);
    }

    public function test_commit_message_names_people_by_display_name_only(): void
    {
        VaultJournalEntry::query()->insert([
            ['vault_store_id' => $this->store->id, 'path' => 'a.md', 'op' => 'write', 'content' => 'a', 'size' => 1, 'git_sha' => GitHubGit::blobSha('a'), 'base_version' => '', 'actor_id' => 'ana@example.com', 'actor_name' => 'Ana', 'created_at' => now(), 'updated_at' => now()],
            ['vault_store_id' => $this->store->id, 'path' => 'b.md', 'op' => 'write', 'content' => 'b', 'size' => 1, 'git_sha' => GitHubGit::blobSha('b'), 'base_version' => '', 'actor_id' => 'ana@example.com', 'actor_name' => 'Ana', 'created_at' => now(), 'updated_at' => now()],
            ['vault_store_id' => $this->store->id, 'path' => 'c.md', 'op' => 'write', 'content' => 'c', 'size' => 1, 'git_sha' => GitHubGit::blobSha('c'), 'base_version' => '', 'actor_id' => 'ana@example.com', 'actor_name' => 'Ana', 'created_at' => now(), 'updated_at' => now()],
        ]);
        app()->setLocale('pt-BR');

        app(GitHubFlusher::class)->flush($this->store);

        $message = $this->github->log('acme/notes')[0];
        $this->assertSame('jotter: 3 notas por Ana (via Jotter)', $message);
        $this->assertStringNotContainsString('@', $message);
    }

    public function test_a_text_over_the_inline_limit_goes_as_a_blob(): void
    {
        $big = "# Big\n\n".str_repeat("linha de texto ção\n", 70000);
        $this->assertGreaterThan(\App\Domain\Vault\Store\GitHub\GitHubGit::INLINE_MAX_BYTES, strlen($big));
        $store = app(VaultStorage::class)->store($this->workspace);
        $store->write('big.md', $big);
        $store->write('small.md', "# Small\n");
        app(GitHubFlusher::class)->flush($this->store);

        $this->assertSame(1, $this->github->countRequests('POST', '/git/blobs'), 'Only the big text leaves the tree request.');
        $this->assertSame($big, $this->github->files('acme/notes')['big.md']);
    }

    public function test_binary_attachments_go_as_blobs_and_deletes_and_moves_reach_github(): void
    {
        $storage = app(VaultStorage::class);
        $store = $storage->store($this->workspace);
        $png = "\x89PNG\r\n\x1a\n".random_bytes(512);
        $store->write('_resources/img.png', $png);
        $storage->write($this->workspace, 'old.md', "# Old\n");
        app(GitHubFlusher::class)->flush($this->store);
        $this->assertSame($png, $this->github->files('acme/notes')['_resources/img.png']);
        $this->assertSame(1, $this->github->countRequests('POST', '/git/blobs'));

        $storage->move($this->workspace, 'old.md', 'new.md');
        $storage->delete($this->workspace, 'README.md');
        app(GitHubFlusher::class)->flush($this->store);

        $files = $this->github->files('acme/notes');
        $this->assertArrayNotHasKey('old.md', $files);
        $this->assertArrayNotHasKey('README.md', $files);
        $this->assertSame("# Old\n", $files['new.md']);
    }

    public function test_an_empty_repository_gets_its_first_commit_through_the_contents_api(): void
    {
        $this->github->createRepo('acme/empty');
        $store = $this->patStore($this->tenant, 'acme/empty', token: 'pat-empty');
        $workspace = $this->workspaceOn($store, 'fresh');
        $storage = app(VaultStorage::class);
        $storage->write($workspace, 'a.md', "# A\n");
        $storage->write($workspace, 'b.md', "# B\n");

        $result = app(GitHubFlusher::class)->flush($store);

        $this->assertSame('flushed', $result['status']);
        $this->assertSame(['a.md' => "# A\n", 'b.md' => "# B\n"], $this->github->files('acme/empty'));
        $this->assertSame(1, $this->github->countRequests('PUT', '/contents/'));
        $this->assertCount(2, $this->github->log('acme/empty'));
    }

    public function test_with_the_budget_exhausted_the_edit_saves_and_the_flush_only_waits(): void
    {
        config(['jotter.github.writes_per_minute' => 2]);
        $storage = app(VaultStorage::class);
        $storage->write($this->workspace, 'a.md', "# A\n");

        $result = app(GitHubFlusher::class)->flush($this->store);

        $this->assertSame('delayed', $result['status']);
        $this->assertSame(0, $this->github->writeCount());
        $this->assertSame("# A\n", $storage->readContents($this->workspace, 'a.md'));
        $this->assertTrue(VaultSyncState::query()->first()->next_flush_at->isFuture());

        config(['jotter.github.writes_per_minute' => 60]);
        $this->travel(2)->minutes();
        $this->assertSame('flushed', app(GitHubFlusher::class)->flush($this->store)['status']);
        $this->assertSame("# A\n", $this->github->files('acme/notes')['a.md']);
    }

    public function test_a_github_rate_limit_answer_delays_without_losing_anything(): void
    {
        $storage = app(VaultStorage::class);
        $storage->write($this->workspace, 'a.md', "# A\n");
        $this->github->rateLimitWrites = 1;

        $result = app(GitHubFlusher::class)->flush($this->store);

        $this->assertSame('delayed', $result['status']);
        $state = VaultSyncState::query()->first();
        $this->assertGreaterThanOrEqual(115, now()->diffInSeconds($state->next_flush_at));
        $this->assertSame(1, VaultJournalEntry::query()->whereNull('flushed_at')->whereNull('flush_id')->count());

        $this->travel(3)->minutes();
        $this->assertSame('flushed', app(GitHubFlusher::class)->flush($this->store)['status']);
        $this->assertSame("# A\n", $this->github->files('acme/notes')['a.md']);
    }

    public function test_a_crash_between_the_commit_and_the_ref_update_neither_duplicates_nor_loses(): void
    {
        $storage = app(VaultStorage::class);
        $storage->write($this->workspace, 'a.md', "# A\n");
        $this->github->crashBeforePatch = true;

        try {
            app(GitHubFlusher::class)->flush($this->store);
            $this->fail('The simulated crash should escape.');
        } catch (\RuntimeException $crash) {
            $this->assertStringContainsString('Simulated crash', $crash->getMessage());
        }
        $state = VaultSyncState::query()->first();
        $this->assertNotNull($state->pending_commit_sha, 'The commit is recorded before the PATCH.');
        $this->assertArrayNotHasKey('a.md', $this->github->files('acme/notes'));

        $result = app(GitHubFlusher::class)->flush($this->store);

        $this->assertSame('flushed', $result['status']);
        $this->assertSame($state->pending_commit_sha, $this->github->head('acme/notes'), 'Only the PATCH was repeated.');
        $this->assertCount(2, $this->github->log('acme/notes'));
        $this->assertSame(0, VaultJournalEntry::query()->whereNull('flushed_at')->count());
    }

    public function test_a_crash_after_the_ref_update_is_finished_on_the_next_run(): void
    {
        $storage = app(VaultStorage::class);
        $storage->write($this->workspace, 'a.md', "# A\n");
        app(GitHubFlusher::class)->flush($this->store);
        $head = $this->github->head('acme/notes');
        // Pretend the process died right after GitHub accepted the PATCH.
        $row = VaultJournalEntry::query()->first();
        $row->forceFill(['flushed_at' => null, 'flush_id' => 'f-1', 'content' => "# A\n"])->save();
        VaultSyncState::query()->update(['pending_flush_id' => 'f-1', 'pending_commit_sha' => $head, 'pending_commit_parent' => sha1('x')]);

        app(GitHubFlusher::class)->flush($this->store);

        $this->assertSame($head, $this->github->head('acme/notes'));
        $this->assertCount(2, $this->github->log('acme/notes'));
        $this->assertNotNull($row->fresh()->flushed_at);
        $this->assertNull(VaultSyncState::query()->first()->pending_commit_sha);
    }

    public function test_a_push_during_the_flush_is_pulled_and_the_batch_redone_on_the_new_head(): void
    {
        $storage = app(VaultStorage::class);
        $storage->write($this->workspace, 'mine.md', "# Mine\n");
        $this->github->beforePatch = fn ($fake) => $fake->push('acme/notes', ['theirs.md' => "# Theirs\n"]);

        $result = app(GitHubFlusher::class)->flush($this->store);

        $this->assertSame('flushed', $result['status']);
        $files = $this->github->files('acme/notes');
        $this->assertSame("# Mine\n", $files['mine.md']);
        $this->assertSame("# Theirs\n", $files['theirs.md']);
        $this->assertNotNull(Note::query()->where('path', 'theirs.md')->first(), 'The external file was pulled in.');
    }

    public function test_github_down_retries_with_backoff_and_reads_keep_working(): void
    {
        $storage = app(VaultStorage::class);
        app(GitHubSync::class)->pull($this->store);
        $this->assertSame("# Notes\n", $storage->readContents($this->workspace, 'README.md'));
        $storage->write($this->workspace, 'a.md', "# A\n");
        $this->github->down = true;

        $first = app(GitHubFlusher::class)->flush($this->store);
        $this->assertSame('retrying', $first['status']);
        $state = VaultSyncState::query()->first();
        $this->assertSame(1, $state->flush_failures);
        $this->assertEqualsWithDelta(60, now()->diffInSeconds($state->next_flush_at), 2);
        $this->assertSame("# Notes\n", $storage->readContents($this->workspace, 'README.md'), 'Cached blobs still serve reads.');
        $this->assertSame('waiting', app(GitHubFlusher::class)->flush($this->store)['status']);

        $this->travel(25)->hours();
        app(GitHubFlusher::class)->flush($this->store);
        $this->assertNotNull(VaultSyncState::query()->first()->failure_notified_at);
        $this->assertDatabaseHas('audit_log', ['event' => 'vault.flush_failed']);

        $this->github->down = false;
        $this->travel(1)->hours();
        $this->assertSame('flushed', app(GitHubFlusher::class)->flush($this->store)['status']);
        $this->assertSame(0, VaultSyncState::query()->first()->flush_failures);
    }
}
