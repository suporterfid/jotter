<?php

namespace Tests\Feature\S3Vault;

use App\Domain\Vault\AttachmentStorage;
use App\Domain\Vault\Store\StoreUnavailable;
use App\Domain\Vault\Store\VaultStoreProbe;
use App\Domain\Vault\Store\VaultStoreResolver;
use App\Domain\Vault\VaultReindexer;
use App\Domain\Vault\VaultStorage;
use App\Models\Attachment;
use App\Models\Membership;
use App\Models\Note;
use App\Models\Tenant;
use App\Models\TenantVaultStore;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithFakeS3;
use Tests\TestCase;

/** The S3 driver inside the app (#446): outages, refused credentials, attachments, reindex. */
class S3VaultBehaviourTest extends TestCase
{
    use InteractsWithFakeS3;
    use RefreshDatabase;

    private Tenant $tenant;

    private TenantVaultStore $store;

    private Workspace $workspace;

    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();
        config(['jotter.auth_bypass' => false, 'jotter.auth_provider' => 'local']);
        $this->setUpFakeS3();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);
        $this->store = $this->s3Store($this->tenant);
        $this->workspace = $this->workspaceOnS3($this->store);
        $this->editor = User::factory()->create(['is_admin' => false]);
        Membership::create(['tenant_id' => $this->tenant->id, 'workspace_id' => $this->workspace->id, 'subject_id' => (string) $this->editor->id, 'role' => 'editor']);
    }

    public function test_notes_are_written_to_the_bucket_and_record_their_etag(): void
    {
        $note = app(VaultStorage::class)->write($this->workspace, 'Home.md', "# Home\n");

        $this->assertSame("# Home\n", $this->s3->object(self::S3_BUCKET, $this->workspace->vault_prefix.'/Home.md')['body']);
        $this->assertSame(md5("# Home\n"), $note->fresh()->remote_version);
        $this->assertSame('text/markdown; charset=utf-8', $this->s3->object(self::S3_BUCKET, $this->workspace->vault_prefix.'/Home.md')['type']);
    }

    public function test_a_save_never_overwrites_an_edit_made_in_the_bucket_after_it_was_read(): void
    {
        $storage = app(VaultStorage::class);
        $storage->write($this->workspace, 'Home.md', "# Home\n");
        // Another tool writes the object while this request still holds the version it saw.
        $this->s3->putExternal(self::S3_BUCKET, $this->workspace->vault_prefix.'/Home.md', "# Theirs\n");

        try {
            $storage->write($this->workspace, 'Home.md', "# Mine\n");
            $this->fail('The If-Match save must be refused.');
        } catch (\App\Domain\Vault\Store\StoreVersionConflict $conflict) {
            $this->assertSame(409, $conflict->render()->getStatusCode());
        }
        $this->assertSame("# Theirs\n", $this->s3->object(self::S3_BUCKET, $this->workspace->vault_prefix.'/Home.md')['body']);
    }

    public function test_the_provider_down_answers_503_and_degrades_the_workspace_until_it_works_again(): void
    {
        $note = app(VaultStorage::class)->write($this->workspace, 'Home.md', "# Home\n");
        $this->newS3Request();
        $this->s3->down();

        $this->actingAs($this->editor)->getJson("/api/workspaces/{$this->workspace->id}/notes/{$note->id}")
            ->assertStatus(503)
            ->assertJsonPath('storage_state', 'degraded')
            ->assertJsonPath('message', __('messages.vault_store_unavailable'));
        $this->assertSame('degraded', $this->workspace->fresh()->storage_state);
        $this->assertSame("# Home\n", $this->s3->object(self::S3_BUCKET, $this->workspace->vault_prefix.'/Home.md')['body'], 'Nothing was corrupted.');

        $this->s3->down(false);
        $this->newS3Request();
        app(VaultStorage::class)->write($this->workspace->fresh(), 'Home.md', "# Home again\n");
        $this->assertSame('active', $this->workspace->fresh()->storage_state, 'A write that reaches the bucket lifts the degraded state.');
    }

    public function test_refused_credentials_fail_the_store_and_writes_answer_423_until_a_test_passes(): void
    {
        $note = app(VaultStorage::class)->write($this->workspace, 'Home.md', "# Home\n");
        $this->newS3Request();
        $this->s3->deny();

        try {
            app(VaultStorage::class)->write($this->workspace->fresh(), 'Home.md', "# Changed\n");
            $this->fail('An access-denied write must not succeed.');
        } catch (StoreUnavailable $unavailable) {
            $this->assertStringNotContainsString(self::S3_SECRET, $unavailable->getMessage());
        }
        $this->assertSame(TenantVaultStore::STATUS_FAILED, $this->store->fresh()->status);
        $this->assertSame('degraded', $this->workspace->fresh()->storage_state);
        $this->assertDatabaseHas('audit_log', ['event' => 'vault_store.failed']);

        // The provider accepts the key again, but nothing is written until an admin tests the connection.
        $this->s3->deny(false);
        $this->newS3Request();
        $this->actingAs($this->editor)->putJson("/api/workspaces/{$this->workspace->id}/notes/{$note->id}", ['content' => "# Mine\n"])
            ->assertStatus(423)->assertJsonPath('storage_state', 'read_only');
        $this->assertSame("# Home\n", $this->s3->object(self::S3_BUCKET, $this->workspace->vault_prefix.'/Home.md')['body']);

        $this->assertTrue(app(VaultStoreProbe::class)->probe($this->store->fresh())['ok']);
        $this->assertSame(TenantVaultStore::STATUS_OK, $this->store->fresh()->status);
        $this->assertSame('active', $this->workspace->fresh()->storage_state);
    }

    public function test_small_attachments_stream_and_large_ones_redirect_to_a_short_presigned_url(): void
    {
        config(['jotter.s3.presign_over_bytes' => 1024]);
        app(AttachmentStorage::class)->writeAttachment($this->workspace, 'tiny', '_resources/tiny.txt', 'text/plain');
        app(AttachmentStorage::class)->writeAttachment($this->workspace, str_repeat('x', 4096), '_resources/big.pdf', 'application/pdf');
        $this->assertSame(md5('tiny'), Attachment::query()->where('path', '_resources/tiny.txt')->value('remote_version'));

        $small = $this->actingAs($this->editor)->get("/api/workspaces/{$this->workspace->id}/attachments/_resources/tiny.txt");
        $small->assertOk();
        $this->assertSame('tiny', $small->streamedContent());

        $big = $this->actingAs($this->editor)->get("/api/workspaces/{$this->workspace->id}/attachments/_resources/big.pdf");
        $big->assertRedirect();
        $location = (string) $big->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertStringStartsWith('https://s3.fake.test/'.self::S3_BUCKET.'/'.$this->workspace->vault_prefix.'/_resources/big.pdf', $location);
        $this->assertSame('60', $query['X-Amz-Expires']);
        $this->assertSame('application/pdf', $query['response-content-type']);
        $this->assertSame('inline', $query['response-content-disposition']);
        $this->assertStringNotContainsString(self::S3_SECRET, $location);
    }

    public function test_reindex_downloads_only_objects_whose_etag_changed(): void
    {
        $storage = app(VaultStorage::class);
        $storage->write($this->workspace, 'A.md', "# A\n");
        $storage->write($this->workspace, 'B.md', "# B\n");
        $this->newS3Request();

        $before = $this->s3->count('GET', 'object');
        app(VaultReindexer::class)->reindex($this->workspace->fresh());
        $this->assertSame(0, $this->s3->count('GET', 'object') - $before, 'Unchanged objects are not downloaded again.');

        // Someone edits B and adds C directly in the bucket, and removes A.
        $this->s3->putExternal(self::S3_BUCKET, $this->workspace->vault_prefix.'/B.md', "# B edited\n");
        $this->s3->putExternal(self::S3_BUCKET, $this->workspace->vault_prefix.'/C.md', "# C\n");
        unset($this->s3->buckets[self::S3_BUCKET][$this->workspace->vault_prefix.'/A.md']);
        $this->newS3Request();

        $before = $this->s3->count('GET', 'object');
        $heads = $this->s3->count('HEAD');
        $result = app(VaultReindexer::class)->reindex($this->workspace->fresh());

        $this->assertSame(2, $this->s3->count('GET', 'object') - $before);
        $this->assertSame(1, $this->s3->count('HEAD') - $heads, 'Only the note missing from the listing is checked.');
        $this->assertSame(['scanned' => 2, 'upserted' => 2, 'removed' => 1], $result);
        $this->assertSame('B edited', Note::query()->where('path', 'B.md')->value('title'));
        $this->assertSame(md5("# C\n"), Note::query()->where('path', 'C.md')->value('remote_version'));
        $this->assertNull(Note::query()->where('path', 'A.md')->first());
    }

    public function test_an_outage_during_reindex_never_deletes_notes_and_the_hourly_run_goes_on(): void
    {
        app(VaultStorage::class)->write($this->workspace, 'Home.md', "# Home\n");
        $other = Workspace::create(['tenant_id' => $this->tenant->id, 'slug' => 'local', 'name' => 'Local', 'vault_path' => storage_path('app/vaults/s3-reindex-'.uniqid())]);
        app(VaultStorage::class)->write($other, 'Local.md', "# Local\n");
        $this->newS3Request();
        $this->s3->down();

        try {
            app(VaultReindexer::class)->reindex($this->workspace->fresh());
            $this->fail('An unreachable bucket must stop the reindex.');
        } catch (StoreUnavailable) {
            $this->assertNotNull(Note::query()->where('path', 'Home.md')->first(), 'No note is deleted because of an outage.');
        }

        $this->artisan('vault:reindex', ['--all' => true])
            ->expectsOutputToContain('Skipped workspace '.$this->workspace->id)
            ->expectsOutputToContain('Reindexed workspace '.$other->id)
            ->assertSuccessful();
        $this->assertNotNull(Note::query()->where('path', 'Home.md')->first());
        \Illuminate\Support\Facades\File::deleteDirectory((string) $other->vault_path);
    }

    public function test_the_connection_test_works_under_the_prefix_and_records_versioning(): void
    {
        $this->s3->versioning[self::S3_BUCKET] = 'Enabled';
        $this->s3->lifecycle[self::S3_BUCKET] = 30;

        $this->assertTrue(app(VaultStoreProbe::class)->probe($this->store)['ok']);

        $state = $this->store->fresh()->settings['bucket_state'];
        $this->assertSame('Enabled', $state['versioning']);
        $this->assertSame(30, $state['noncurrent_expiry_days']);
        $this->assertSame([], $this->s3->keys(self::S3_BUCKET, 'tenants/acme/.jotter-probe'), 'The probe object is removed.');
        $puts = array_filter($this->s3->requests, fn (array $r): bool => $r['method'] === 'PUT');
        $this->assertStringStartsWith(self::S3_BUCKET.'/tenants/acme/.jotter-probe-', end($puts)['path']);
    }

    public function test_the_workspace_badge_turns_read_only_when_the_store_failed(): void
    {
        $this->assertSame('synced', \App\Domain\Vault\Store\GitHub\GitHubLifecycle::status($this->workspace)['state']);
        $this->store->forceFill(['status' => TenantVaultStore::STATUS_FAILED])->save();
        $this->assertSame('read_only', \App\Domain\Vault\Store\GitHub\GitHubLifecycle::status($this->workspace)['state']);
    }

    public function test_a_store_without_a_prefix_refuses_to_empty_the_bucket(): void
    {
        $record = $this->s3Store($this->tenant, ['prefix' => '']);
        $store = app(VaultStoreResolver::class)->forStore($record, $this->workspace, '');

        $this->expectException(\RuntimeException::class);
        $store->deleteDirectory('');
    }
}
