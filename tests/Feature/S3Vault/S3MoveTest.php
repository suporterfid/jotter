<?php

namespace Tests\Feature\S3Vault;

use App\Domain\Vault\AttachmentStorage;
use App\Domain\Vault\Move\VaultMover;
use App\Domain\Vault\Store\VaultStoreResolver;
use App\Domain\Vault\VaultReindexer;
use App\Domain\Vault\VaultStorage;
use App\Models\Attachment;
use App\Models\Note;
use App\Models\NoteLink;
use App\Models\Tenant;
use App\Models\TenantVaultStore;
use App\Models\VaultMove;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Support\InteractsWithFakeS3;
use Tests\TestCase;

/** vault:move local ↔ S3 (#447): verified by SHA-256, search and backlinks unchanged. */
class S3MoveTest extends TestCase
{
    use InteractsWithFakeS3;
    use RefreshDatabase;

    private Tenant $tenant;

    private Workspace $workspace;

    private TenantVaultStore $store;

    /** @var array<string, string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFakeS3();

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);
        $this->workspace = Workspace::create([
            'tenant_id' => $this->tenant->id, 'slug' => 'default', 'name' => 'Default',
            'vault_path' => storage_path('app/vaults/s3-move-'.uniqid()),
        ]);
        $storage = app(VaultStorage::class);
        foreach (['Home.md' => "# Home\n\n[[Ideas/One]] and [[Two]]\n", 'Ideas/One.md' => "# One ção\n\nzebra\n", 'Ideas/Two.md' => "# Two\n\n[[Home]]\n"] as $path => $body) {
            $storage->write($this->workspace, $path, $body);
            $this->files[$path] = $body;
        }
        $png = "\x89PNG\r\n\x1a\n".random_bytes(2048);
        app(AttachmentStorage::class)->writeAttachment($this->workspace, $png, '_resources/pic.png', 'image/png');
        $this->files['_resources/pic.png'] = $png;
        ksort($this->files, SORT_STRING);

        $this->store = $this->s3Store($this->tenant);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory((string) $this->workspace->vault_path);
        parent::tearDown();
    }

    public function test_the_vault_goes_to_s3_and_back_without_losing_a_file_and_search_and_backlinks_stay(): void
    {
        $links = $this->links();
        $search = $this->search('zebra');
        $this->assertNotSame([], $search);
        $notes = Note::query()->where('workspace_id', $this->workspace->id)->orderBy('path')->pluck('title', 'path')->all();
        $mover = app(VaultMover::class);

        $move = $mover->runToCompletion($mover->start($this->workspace, $this->store, 'admin'), 2);

        $this->assertSame(VaultMove::COMPLETED, $move->status);
        $workspace = $this->workspace->fresh();
        $this->assertSame('tenants/acme/default-'.$workspace->id, $workspace->vault_prefix);
        $this->assertSame($this->files, $this->bucketFiles($workspace->vault_prefix));
        foreach ($this->files as $path => $bytes) {
            $object = $this->s3->object(self::S3_BUCKET, $workspace->vault_prefix.'/'.$path);
            $this->assertSame(hash('sha256', $bytes), $object['meta']['sha256'], $path);
            $version = Note::query()->where('path', $path)->value('remote_version') ?? Attachment::query()->where('path', $path)->value('remote_version');
            $this->assertSame(md5($bytes), $version, $path);
        }
        $this->assertSame(0, $this->s3->count('GET', 'object'), 'The MD5 ETag and the SHA-256 metadata verify the copy without downloading it.');

        app(VaultReindexer::class)->reindex($workspace);
        $this->assertSame($notes, Note::query()->where('workspace_id', $workspace->id)->orderBy('path')->pluck('title', 'path')->all());
        $this->assertSame($links, $this->links());
        $this->assertSame($search, $this->search('zebra'));

        // And back to the local folder (the anti-lock-in exit).
        $back = $mover->runToCompletion($mover->start($workspace, null, 'admin'), 2);
        $this->assertSame(VaultMove::COMPLETED, $back->status);
        $local = app(VaultStoreResolver::class)->for($this->workspace->fresh());
        foreach ($this->files as $path => $bytes) {
            $this->assertSame(hash('sha256', $bytes), hash('sha256', $local->read($path)), $path);
        }
        app(VaultReindexer::class)->reindex($this->workspace->fresh());
        $this->assertSame($links, $this->links());
        $this->assertSame($search, $this->search('zebra'));

        // The S3 copy is the retained source of the second move; finalizing deletes it.
        $mover->finalize($back->fresh(), 'admin');
        $this->assertSame([], $this->s3->keys(self::S3_BUCKET, $workspace->vault_prefix.'/'));
    }

    public function test_when_the_etag_is_not_an_md5_the_copy_is_read_back(): void
    {
        $mover = app(VaultMover::class);
        $this->s3->opaqueEtags();

        $move = $mover->runToCompletion($mover->start($this->workspace, $this->store, 'admin'), 10);

        $this->assertSame(VaultMove::COMPLETED, $move->status);
        $this->assertSame(count($this->files), $this->s3->count('GET', 'object'), 'Each copy is downloaded once to check its SHA-256.');
    }

    public function test_the_preflight_warns_when_bucket_versioning_is_off(): void
    {
        $mover = app(VaultMover::class);
        $mover->start($this->workspace, $this->store, 'admin');

        $this->assertCount(1, $mover->warnings);
        $this->assertStringContainsString('bucket versioning is off', $mover->warnings[0]);
        $this->assertSame('Off', $this->store->fresh()->settings['bucket_state']['versioning']);
    }

    public function test_a_versioned_bucket_with_a_short_expiry_gives_no_warning(): void
    {
        $this->s3->versioning[self::S3_BUCKET] = 'Enabled';
        $this->s3->lifecycle[self::S3_BUCKET] = 30;
        $mover = app(VaultMover::class);
        $mover->start($this->workspace, $this->store, 'admin');

        $this->assertSame([], $mover->warnings);
    }

    public function test_cli_and_api_show_the_warning(): void
    {
        $this->artisan('vault:move', ['workspace' => $this->workspace->id, '--to' => $this->store->id, '--sync' => true])
            ->expectsOutputToContain('bucket versioning is off')
            ->assertSuccessful();
        $this->assertSame($this->files, $this->bucketFiles((string) $this->workspace->fresh()->vault_prefix));
    }

    public function test_a_non_empty_prefix_is_refused(): void
    {
        $prefix = 'tenants/acme/default-'.$this->workspace->id;
        $this->s3->putExternal(self::S3_BUCKET, $prefix.'/stray.md', 'x');

        $this->expectException(\App\Domain\Vault\Move\VaultMoveRefused::class);
        $this->expectExceptionMessage('not empty');
        app(VaultMover::class)->start($this->workspace, $this->store, 'admin');
    }

    /** @return array<string, string> */
    private function bucketFiles(string $prefix): array
    {
        $files = [];
        foreach ($this->s3->keys(self::S3_BUCKET, $prefix.'/') as $key) {
            if (! str_ends_with($key, '/')) {
                $files[substr($key, strlen($prefix) + 1)] = $this->s3->object(self::S3_BUCKET, $key)['body'];
            }
        }
        ksort($files, SORT_STRING);

        return $files;
    }

    /**
     * What search reads: the indexed text of every note (full-text MATCH cannot
     * see rows inside the test transaction) and a title search.
     *
     * @return array<string, mixed>
     */
    private function search(string $query): array
    {
        $workspace = $this->workspace->fresh();
        $titles = app(\App\Domain\Search\WorkspaceSearch::class)->search($workspace, new \App\Domain\Search\SearchCriteria(title: 'One'));

        return [
            'content' => Note::query()->where('workspace_id', $workspace->id)->where('search_content', 'like', '%'.$query.'%')->orderBy('path')->pluck('search_content', 'path')->all(),
            'titles' => array_column($titles, 'path'),
        ];
    }

    /** @return list<string> */
    private function links(): array
    {
        return NoteLink::query()->with(['sourceNote', 'targetNote'])->get()
            ->map(fn (NoteLink $link): string => $link->sourceNote?->path.' → '.($link->targetNote?->path ?? '?'))
            ->sort()->values()->all();
    }
}
