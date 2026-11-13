<?php

namespace Tests\Feature\GitHubVault;

use App\Domain\Notifications\NotificationType;
use App\Domain\Vault\NoteTrash;
use App\Domain\Vault\Store\GitHub\GitHubFlusher;
use App\Domain\Vault\Store\GitHub\GitHubSync;
use App\Domain\Vault\Store\WorkspaceStorageReadOnly;
use App\Domain\Vault\VaultStorage;
use App\Models\Membership;
use App\Models\Note;
use App\Models\NoteLink;
use App\Models\NoteRevision;
use App\Models\Notification;
use App\Models\Tenant;
use App\Models\TenantVaultStore;
use App\Models\User;
use App\Models\VaultJournalEntry;
use App\Models\VaultSyncState;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithFakeGitHub;
use Tests\TestCase;

/**
 * Pull of external changes (#440) and every row of the conflict table in
 * plan §5.6 (#442), against the in-memory GitHub.
 */
class GitHubPullTest extends TestCase
{
    use InteractsWithFakeGitHub;
    use RefreshDatabase;

    private Tenant $tenant;

    private TenantVaultStore $store;

    private Workspace $workspace;

    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFakeGitHub();
        $this->github->createRepo('acme/notes', [
            'Home.md' => "# Home\n\nSee [[Projects/Alpha]] and #team\n",
            'Projects/Alpha.md' => "---\nstatus: active\n---\n# Alpha\n\nBack to [[Home]].\n",
            '_resources/logo.png' => "\x89PNG\r\n\x1a\nlogo",
        ]);
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);
        $this->store = $this->patStore($this->tenant);
        $this->workspace = $this->workspaceOn($this->store);
        $this->editor = User::factory()->create(['name' => 'Ana', 'email' => 'ana@example.com']);
        Membership::create(['subject_id' => (string) $this->editor->id, 'tenant_id' => $this->tenant->id, 'role' => 'owner']);
    }

    private function pull(?string $login = null): array
    {
        return app(GitHubSync::class)->pull($this->store, actorLogin: $login);
    }

    private function flush(): array
    {
        return app(GitHubFlusher::class)->flush($this->store, ignoreBackoff: true);
    }

    private function editAs(string $path, string $contents): void
    {
        app(VaultStorage::class)->write($this->workspace, $path, $contents, (string) $this->editor->id);
        VaultJournalEntry::query()->where('path', $path)->update(['actor_id' => (string) $this->editor->id, 'actor_name' => 'Ana']);
    }

    public function test_an_existing_repository_appears_searchable_with_backlinks(): void
    {
        $result = $this->pull();

        $this->assertSame('pulled', $result['status']);
        $home = Note::query()->where('path', 'Home.md')->firstOrFail();
        $alpha = Note::query()->where('path', 'Projects/Alpha.md')->firstOrFail();
        $this->assertSame('Home', $home->title);
        $this->assertStringContainsString('See', (string) $home->search_content);
        $this->assertSame(['status' => 'active'], $alpha->frontmatter);
        $this->assertTrue(NoteLink::query()->where('source_note_id', $home->id)->where('target_note_id', $alpha->id)->exists());
        $this->assertTrue(NoteLink::query()->where('source_note_id', $alpha->id)->where('target_note_id', $home->id)->exists());
        $this->assertSame(0, Note::query()->where('path', 'like', '_resources/%')->count(), 'Attachments are not notes.');
        $this->assertSame("\x89PNG\r\n\x1a\nlogo", app(VaultStorage::class)->store($this->workspace)->read('_resources/logo.png'));
    }

    public function test_an_unchanged_ref_costs_one_conditional_request(): void
    {
        $this->pull();
        $before = count($this->github->requests);

        $result = $this->pull();

        $this->assertSame('not_modified', $result['status']);
        $this->assertSame($before + 1, count($this->github->requests));
    }

    public function test_only_github_changed_updates_the_note_with_a_github_revision(): void
    {
        $this->pull();
        $this->github->push('acme/notes', ['Home.md' => "# Home\n\nEdited on GitHub.\n", 'New.md' => "# New\n", 'Projects/Alpha.md' => null]);

        $this->pull('octocat');

        $home = Note::query()->where('path', 'Home.md')->firstOrFail();
        $this->assertStringContainsString('Edited on GitHub', (string) $home->search_content);
        $this->assertSame('github:octocat', NoteRevision::query()->where('note_id', $home->id)->latest('id')->value('actor_id'));
        $this->assertNotNull(Note::query()->where('path', 'New.md')->first());
        $this->assertNull(Note::query()->where('path', 'Projects/Alpha.md')->first());
    }

    public function test_only_jotter_changed_is_a_plain_fast_forward(): void
    {
        $this->pull();
        $this->editAs('Home.md', "# Home\n\nEdited in Jotter.\n");

        $this->flush();

        $this->assertSame("# Home\n\nEdited in Jotter.\n", $this->github->files('acme/notes')['Home.md']);
        $this->assertSame(0, Note::query()->where('path', 'like', '%conflito%')->orWhere('path', 'like', '%conflict%')->count());
    }

    public function test_different_files_on_both_sides_merge_without_conflict(): void
    {
        $this->pull();
        $this->editAs('Home.md', "# Home\n\nJotter side.\n");
        $this->github->push('acme/notes', ['Projects/Alpha.md' => "# Alpha\n\nGitHub side.\n"]);

        $this->flush();

        $files = $this->github->files('acme/notes');
        $this->assertSame("# Home\n\nJotter side.\n", $files['Home.md']);
        $this->assertSame("# Alpha\n\nGitHub side.\n", $files['Projects/Alpha.md']);
        $this->assertCount(3, $files);
    }

    public function test_the_same_file_on_both_sides_keeps_both_versions(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 13)->setTime(14, 5));
        app()->setLocale('pt-BR');
        $this->pull();
        $this->editAs('Home.md', "# Home\n\nJotter version.\n");
        $this->github->push('acme/notes', ['Home.md' => "# Home\n\nGitHub version.\n"]);

        $this->flush();

        $files = $this->github->files('acme/notes');
        $this->assertSame("# Home\n\nJotter version.\n", $files['Home.md']);
        $this->assertSame("# Home\n\nGitHub version.\n", $files['Home (conflito GitHub 2026-11-13 1405).md']);
        $this->assertNotNull(Note::query()->where('path', 'Home (conflito GitHub 2026-11-13 1405).md')->first());
        $notification = Notification::query()->where('user_id', $this->editor->id)->where('type', NotificationType::VAULT_SYNC->value)->first();
        $this->assertNotNull($notification);
        $this->assertSame('conflict_copy', $notification->data['kind']);
        $this->assertDatabaseHas('audit_log', ['event' => 'vault.conflict']);
    }

    public function test_the_same_content_on_both_sides_is_not_a_conflict(): void
    {
        $this->pull();
        $this->editAs('Home.md', "# Home\n\nSame.\n");
        $this->github->push('acme/notes', ['Home.md' => "# Home\n\nSame.\n"]);
        $writesBefore = $this->github->writeCount();

        $this->flush();

        $this->assertSame(0, VaultJournalEntry::query()->whereNull('flushed_at')->count());
        $this->assertSame($writesBefore, $this->github->writeCount(), 'Nothing left to commit.');
        $this->assertCount(3, $this->github->files('acme/notes'));
    }

    public function test_jotter_edit_wins_over_a_github_delete_and_the_file_comes_back(): void
    {
        $this->pull();
        $this->editAs('Projects/Alpha.md', "# Alpha\n\nStill needed.\n");
        $this->github->push('acme/notes', ['Projects/Alpha.md' => null]);

        $this->flush();

        $this->assertSame("# Alpha\n\nStill needed.\n", $this->github->files('acme/notes')['Projects/Alpha.md']);
        $this->assertSame('restored_after_remote_delete', Notification::query()->where('user_id', $this->editor->id)->value('data')['kind']);
    }

    public function test_github_edit_wins_over_a_jotter_delete_and_the_note_leaves_the_trash(): void
    {
        $this->pull();
        $alpha = Note::query()->where('path', 'Projects/Alpha.md')->firstOrFail();
        app(NoteTrash::class)->trash($this->workspace, $alpha);
        $this->assertTrue($alpha->fresh()->trashed());
        $this->github->push('acme/notes', ['Projects/Alpha.md' => "# Alpha\n\nEdited after the delete.\n"]);

        $this->flush();

        $alpha = Note::withTrashed()->findOrFail($alpha->id);
        $this->assertFalse($alpha->trashed());
        $this->assertSame('Projects/Alpha.md', $alpha->path);
        $this->assertStringContainsString('Edited after the delete', (string) $alpha->search_content);
        $files = $this->github->files('acme/notes');
        $this->assertSame("# Alpha\n\nEdited after the delete.\n", $files['Projects/Alpha.md']);
        $this->assertEmpty(array_filter(array_keys($files), fn ($p) => str_starts_with($p, '.trash/')), 'The trash copy never reaches GitHub.');
    }

    public function test_renamed_on_github_and_edited_in_jotter_loses_nothing(): void
    {
        $this->pull();
        $this->editAs('Projects/Alpha.md', "# Alpha\n\nJotter edit.\n");
        $this->github->push('acme/notes', ['Projects/Alpha.md' => null, 'Projects/Alpha renamed.md' => "---\nstatus: active\n---\n# Alpha\n\nBack to [[Home]].\n"]);

        $this->flush();

        $files = $this->github->files('acme/notes');
        $this->assertSame("# Alpha\n\nJotter edit.\n", $files['Projects/Alpha.md']);
        $this->assertArrayHasKey('Projects/Alpha renamed.md', $files);
    }

    public function test_case_twins_are_renamed_and_reported(): void
    {
        $this->pull();
        $this->github->push('acme/notes', ['home.md' => "# lower\n"]);

        $this->pull();
        $this->flush();

        $files = $this->github->files('acme/notes');
        $this->assertArrayHasKey('Home.md', $files);
        $this->assertArrayNotHasKey('home.md', $files);
        $this->assertSame("# lower\n", $files['home (2).md']);
        $this->assertSame('case_twin', VaultSyncState::query()->first()->warnings['renamed:home.md']['type']);
    }

    public function test_nfd_paths_become_nfc(): void
    {
        $nfd = "Cafe\u{0301}.md";
        $nfc = "Caf\u{00E9}.md";
        $this->github->push('acme/notes', [$nfd => "# Café\n"]);

        $this->pull();
        $this->flush();

        $files = $this->github->files('acme/notes');
        $this->assertArrayHasKey($nfc, $files);
        $this->assertArrayNotHasKey($nfd, $files);
        $this->assertNotNull(Note::query()->where('path', $nfc)->first());
    }

    public function test_git_metadata_symlinks_submodules_and_big_files_are_ignored_and_never_deleted(): void
    {
        config(['jotter.github.max_file_bytes' => 1024]);
        $this->github->push('acme/notes', ['.gitignore' => "*.tmp\n", '.github/workflows/x.yml' => 'on: push', 'big.bin' => str_repeat('x', 4096)]);
        $this->github->pushSpecial('acme/notes', 'link.md', '120000');
        $this->github->pushSpecial('acme/notes', 'vendor/lib', '160000');

        $this->pull();
        app(VaultStorage::class)->write($this->workspace, 'a.md', "# A\n");
        app(VaultStorage::class)->store($this->workspace)->deleteDirectory('');
        $this->flush();

        $warnings = VaultSyncState::query()->first()->warnings;
        foreach (['.gitignore' => 'git metadata', '.github/workflows/x.yml' => 'git metadata', 'big.bin' => 'larger than 1024 bytes', 'link.md' => 'symlink', 'vendor/lib' => 'submodule'] as $path => $reason) {
            $this->assertSame($reason, $warnings['ignored:'.$path]['reason'], $path);
        }
        $files = $this->github->files('acme/notes');
        foreach (['.gitignore', '.github/workflows/x.yml', 'big.bin', 'link.md'] as $path) {
            $this->assertArrayHasKey($path, $files, "{$path} must survive Jotter deleting everything it knows.");
        }
        $this->assertArrayNotHasKey('Home.md', $files);
    }

    public function test_a_deleted_branch_turns_the_store_failed_and_the_workspace_read_only(): void
    {
        $this->pull();
        $this->assertSame("# Home\n\nSee [[Projects/Alpha]] and #team\n", app(VaultStorage::class)->readContents($this->workspace, 'Home.md'));
        app(VaultStorage::class)->store($this->workspace)->read('_resources/logo.png');
        $this->editAs('Home.md', "# Home\n\nPending.\n");
        $this->github->deleteBranch('acme/notes');

        $this->flush();

        $this->assertSame('failed', $this->store->fresh()->status);
        $this->assertSame('degraded', $this->workspace->fresh()->storage_state);
        $this->assertSame(1, VaultJournalEntry::query()->whereNull('flushed_at')->count(), 'The journal is kept.');
        $this->assertSame("# Home\n\nPending.\n", app(VaultStorage::class)->readContents($this->workspace, 'Home.md'));
        $this->assertSame("\x89PNG\r\n\x1a\nlogo", app(VaultStorage::class)->store($this->workspace)->read('_resources/logo.png'), 'Cached content still reads.');
        $this->assertSame('store_failed', Notification::query()->where('user_id', $this->editor->id)->latest('id')->value('data')['kind']);

        $this->expectException(WorkspaceStorageReadOnly::class);
        app(VaultStorage::class)->write($this->workspace, 'x.md', 'x');
    }

    public function test_a_revoked_token_fails_the_store(): void
    {
        $this->pull();
        unset($this->github->pats['github_pat_test']);
        $this->github->push('acme/notes', ['Home.md' => "# changed\n"]);

        $this->pull();

        $this->assertSame('failed', $this->store->fresh()->status);
        $this->assertStringContainsString('401', (string) $this->store->fresh()->last_error);
    }

    public function test_a_force_push_is_reconciled_from_the_full_tree(): void
    {
        $this->pull();
        $this->github->forcePush('acme/notes', ['Home.md' => "# Rewritten\n", 'Other.md' => "# Other\n"]);

        $this->pull();

        $this->assertSame(['Home.md', 'Other.md'], Note::query()->orderBy('path')->pluck('path')->all());
        $this->assertSame("# Rewritten\n", app(VaultStorage::class)->readContents($this->workspace, 'Home.md'));
        $warnings = VaultSyncState::query()->first()->warnings;
        $this->assertNotEmpty(array_filter($warnings, fn ($w) => $w['type'] === 'force_push'));
    }

    public function test_a_bounded_pull_resumes_where_it_stopped(): void
    {
        $changes = [];
        for ($i = 0; $i < 7; $i++) {
            $changes["bulk/{$i}.md"] = "# {$i}\n";
        }
        $this->pull();
        $this->github->push('acme/notes', $changes);

        $first = app(GitHubSync::class)->pull($this->store, maxFiles: 3);
        $this->assertSame('partial', $first['status']);
        $this->assertTrue(VaultSyncState::query()->first()->pull_pending);

        $this->artisan('vault:pull', ['--pending' => true])->assertSuccessful();

        $this->assertSame(7, Note::query()->where('path', 'like', 'bulk/%')->count());
        $this->assertFalse(VaultSyncState::query()->first()->pull_pending);
    }
}
