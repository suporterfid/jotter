<?php

namespace Tests\Support;

use App\Domain\Vault\Exceptions\PathTraversalRejected;
use App\Domain\Vault\Exceptions\VaultNoteNotFound;
use App\Domain\Vault\Store\StoreVersionConflict;
use App\Domain\Vault\Store\VaultStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Behaviour every VaultStore driver must share. A driver test extends this
 * class and returns a fresh, empty store from makeStore(); the GitHub and S3
 * drivers reuse it unchanged (docs/20261113-jotter-pluggable-vault-stores-plan.md §4.1).
 */
abstract class VaultStoreContractTest extends TestCase
{
    use RefreshDatabase;

    abstract protected function makeStore(): VaultStore;

    public function test_write_then_read_returns_the_same_bytes(): void
    {
        $store = $this->makeStore();
        $bytes = "# Título\n\nçãõ \x00\xff binary-safe\n";

        $entry = $store->write('notes/a.md', $bytes);

        $this->assertSame('notes/a.md', $entry->path);
        $this->assertSame(strlen($bytes), $entry->size);
        $this->assertNotNull($entry->version);
        $this->assertSame($bytes, $store->read('notes/a.md'));
    }

    public function test_stat_and_exists_reflect_files_only(): void
    {
        $store = $this->makeStore();
        $store->write('folder/file.md', 'x');

        $this->assertTrue($store->exists('folder/file.md'));
        $this->assertFalse($store->exists('folder/missing.md'));
        $this->assertNull($store->stat('folder/missing.md'));
        $this->assertNull($store->stat('folder'));
        $this->assertTrue($store->isDirectory('folder'));
        $this->assertFalse($store->isDirectory('folder/file.md'));

        $stat = $store->stat('folder/file.md');
        $this->assertNotNull($stat);
        $this->assertSame(1, $stat->size);
        $this->assertNotNull($stat->version);
    }

    public function test_version_changes_with_content(): void
    {
        $store = $this->makeStore();
        $first = $store->write('v.md', 'one')->version;
        $second = $store->write('v.md', 'two')->version;

        $this->assertNotSame($first, $second);
        $this->assertSame($second, $store->stat('v.md')?->version);
    }

    public function test_read_of_a_missing_file_throws_not_found(): void
    {
        $this->expectException(VaultNoteNotFound::class);

        $this->makeStore()->read('nope.md');
    }

    public function test_conditional_write_and_delete_detect_conflicts(): void
    {
        $store = $this->makeStore();
        $version = $store->write('c.md', 'base')->version;

        $store->write('c.md', 'mine', $version);
        $this->assertSame('mine', $store->read('c.md'));

        try {
            $store->write('c.md', 'stale', $version);
            $this->fail('A stale expected version must be refused.');
        } catch (StoreVersionConflict $conflict) {
            $this->assertSame('c.md', $conflict->path);
        }
        $this->assertSame('mine', $store->read('c.md'));

        // '' = create only.
        $store->write('new.md', 'fresh', '');
        $this->expectException(StoreVersionConflict::class);
        $store->write('new.md', 'again', '');
    }

    public function test_conditional_delete_refuses_a_stale_version(): void
    {
        $store = $this->makeStore();
        $store->write('d.md', 'one');

        try {
            $store->delete('d.md', 'not-the-version');
            $this->fail('A stale expected version must be refused.');
        } catch (StoreVersionConflict) {
            $this->assertTrue($store->exists('d.md'));
        }

        $store->delete('d.md', $store->stat('d.md')?->version);
        $this->assertFalse($store->exists('d.md'));
    }

    public function test_delete_of_a_missing_file_throws_not_found(): void
    {
        $this->expectException(VaultNoteNotFound::class);

        $this->makeStore()->delete('nope.md');
    }

    public function test_move_renames_and_refuses_an_existing_destination(): void
    {
        $store = $this->makeStore();
        $store->write('from.md', 'body');
        $store->write('taken.md', 'other');

        $store->move('from.md', 'deep/to.md');
        $this->assertFalse($store->exists('from.md'));
        $this->assertSame('body', $store->read('deep/to.md'));

        $this->expectException(\RuntimeException::class);
        $store->move('deep/to.md', 'taken.md');
    }

    public function test_list_is_recursive_sorted_and_resumable(): void
    {
        $store = $this->makeStore();
        foreach (['b.md', 'a/2.md', 'a/1.md', 'c/d/e.png', 'z.md'] as $path) {
            $store->write($path, $path);
        }
        $store->makeDirectory('empty');

        $seen = [];
        $cursor = null;
        $pages = 0;
        do {
            $page = $store->list('', $cursor, 2);
            $this->assertLessThanOrEqual(2, count($page->entries));
            foreach ($page->entries as $entry) {
                $seen[] = $entry->path;
                $this->assertSame(strlen($entry->path), $entry->size);
            }
            $cursor = $page->nextCursor;
            $pages++;
        } while ($cursor !== null && $pages < 10);

        $this->assertSame(['a/1.md', 'a/2.md', 'b.md', 'c/d/e.png', 'z.md'], $seen);
        $this->assertSame(['a/1.md', 'a/2.md'], array_map(fn ($e) => $e->path, $store->list('a')->entries));
        $this->assertSame([], $store->list('missing')->entries);
    }

    public function test_write_stream_and_read_stream_round_trip(): void
    {
        $store = $this->makeStore();
        $source = fopen('php://temp', 'w+b');
        fwrite($source, str_repeat('0123456789', 10_000));
        rewind($source);

        $entry = $store->writeStream('_resources/big.bin', $source);
        fclose($source);
        $this->assertSame(100_000, $entry->size);

        $stream = $store->readStream('_resources/big.bin');
        $this->assertSame(str_repeat('0123456789', 10_000), stream_get_contents($stream));
        fclose($stream);
    }

    public function test_directories_can_be_created_and_removed(): void
    {
        $store = $this->makeStore();
        $store->makeDirectory('one/two');
        $this->assertTrue($store->isDirectory('one/two'));

        $store->write('one/two/file.md', 'x');
        $store->deleteDirectory('one');

        $this->assertFalse($store->exists('one/two/file.md'));
        $this->assertFalse($store->isDirectory('one'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsafePaths(): iterable
    {
        yield 'parent segment' => ['../escape.md'];
        yield 'inner parent segment' => ['a/../../escape.md'];
        yield 'absolute' => ['/etc/passwd'];
        yield 'drive letter' => ['C:/Windows/win.ini'];
        yield 'NUL byte' => ["a\0b.md"];
        yield 'git metadata' => ['.git/config'];
        yield 'nested git metadata' => ['sub/.git/HEAD'];
        yield 'over 1024 bytes' => [str_repeat('a', 1030).'.md'];
    }

    #[DataProvider('unsafePaths')]
    public function test_unsafe_paths_are_rejected(string $path): void
    {
        $this->expectException(PathTraversalRejected::class);

        $this->makeStore()->write($path, 'x');
    }

    public function test_capabilities_are_declared(): void
    {
        $capabilities = $this->makeStore()->capabilities();

        $this->assertIsBool($capabilities->conditionalWrite);
        $this->assertIsBool($capabilities->writeBehind);
    }
}
