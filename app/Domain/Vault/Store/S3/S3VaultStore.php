<?php

namespace App\Domain\Vault\Store\S3;

use App\Domain\Vault\Exceptions\VaultNoteNotFound;
use App\Domain\Vault\Store\StoreCapabilities;
use App\Domain\Vault\Store\StoreEntry;
use App\Domain\Vault\Store\StorePage;
use App\Domain\Vault\Store\StoreUnavailable;
use App\Domain\Vault\Store\StoreVersionConflict;
use App\Domain\Vault\Store\VaultPathNormalizer;
use App\Domain\Vault\Store\VaultStore;
use App\Domain\Vault\Store\WorkspaceStorageReadOnly;
use App\Models\TenantVaultStore;
use App\Models\Workspace;
use AsyncAws\Core\Exception\Http\ClientException;
use AsyncAws\Core\Exception\Http\HttpException;
use AsyncAws\Core\Exception\Http\NetworkException;
use AsyncAws\Core\Exception\Http\RedirectionException;
use AsyncAws\Core\Exception\Http\ServerException;
use AsyncAws\S3\Input\GetObjectRequest;
use AsyncAws\S3\S3Client;
use Illuminate\Support\Facades\DB;

/**
 * A workspace vault in an S3-compatible bucket (AWS S3, Cloudflare R2, MinIO;
 * GCS through its S3 interoperability API), under the workspace's key prefix
 * (`<settings.prefix>/<slug>-<id>/`, plan §4.3). Writes are synchronous (D4).
 *
 * - Versions are ETags (quotes stripped). Every object carries
 *   `x-amz-meta-sha256`, which vault:move verifies against.
 * - Conditional writes use `If-Match` / `If-None-Match: *` where the provider
 *   supports them (AWS, R2, MinIO). Without them (GCS, or
 *   `settings.conditional_writes = false`), a MySQL named lock per object plus
 *   a HEAD comparison does the same for writes that go through Jotter.
 *   Conditional deletes always use the lock + HEAD (not every provider
 *   accepts `If-Match` on DELETE).
 * - Directories are `folder/` marker objects; list() never returns them.
 * - Moves are CopyObject + DeleteObject per object; no batch DeleteObjects,
 *   which GCS does not implement.
 * - Reads are memoized for the life of this instance (one request), bounded.
 * - The provider down (network error, 5xx) → StoreUnavailable (503) and the
 *   workspace turns `degraded`; access refused (401/403) → the store turns
 *   `failed` and writes answer 423 until the connection is tested again.
 */
final class S3VaultStore implements VaultStore
{
    /** Bodies at or under this size are kept in the per-request memo. */
    private const MEMO_MAX_BYTES = 1024 * 1024;

    private const MEMO_MAX_ENTRIES = 64;

    /** @var array<string, string> */
    private array $bodies = [];

    /** @var array<string, StoreEntry|false> */
    private array $stats = [];

    public function __construct(
        private readonly S3Client $client,
        private readonly string $bucket,
        private readonly string $prefix,
        private readonly Workspace $workspace,
        private readonly ?TenantVaultStore $record = null,
        private readonly bool $conditionalWrites = true,
        private readonly ?S3StoreFactory $factory = null,
    ) {}

    public function driver(): string
    {
        return 's3';
    }

    public function client(): S3Client
    {
        return $this->client;
    }

    public function bucket(): string
    {
        return $this->bucket;
    }

    /** Key prefix of this view without the trailing slash ('' = bucket root). */
    public function prefix(): string
    {
        return $this->prefix;
    }

    public function read(string $path): string
    {
        $path = $this->normalize($path);
        if (isset($this->bodies[$path])) {
            return $this->bodies[$path];
        }

        $body = $this->call(function () use ($path): ?string {
            try {
                $result = $this->client->getObject(['Bucket' => $this->bucket, 'Key' => $this->key($path)]);
                $result->resolve();

                return $result->getBody()->getContentAsString();
            } catch (ClientException $exception) {
                if ($this->status($exception) === 404) {
                    return null;
                }
                throw $exception;
            }
        });

        if ($body === null) {
            $this->stats[$path] = false;
            throw new VaultNoteNotFound($path);
        }

        $this->remember($path, $body);

        return $body;
    }

    public function readStream(string $path)
    {
        $path = $this->normalize($path);
        if (isset($this->bodies[$path])) {
            return $this->memoryStream($this->bodies[$path]);
        }

        $stream = $this->call(function () use ($path) {
            try {
                $result = $this->client->getObject(['Bucket' => $this->bucket, 'Key' => $this->key($path)]);
                $result->resolve();

                return $result->getBody()->getContentAsResource();
            } catch (ClientException $exception) {
                if ($this->status($exception) === 404) {
                    return null;
                }
                throw $exception;
            }
        });

        if ($stream === null) {
            throw new VaultNoteNotFound($path);
        }

        return $stream;
    }

    public function stat(string $path): ?StoreEntry
    {
        $path = $this->normalize($path);
        if (array_key_exists($path, $this->stats)) {
            return $this->stats[$path] ?: null;
        }

        $entry = $this->head($path);
        $this->stats[$path] = $entry ?? false;

        return $entry;
    }

    public function exists(string $path): bool
    {
        return $this->stat($path) !== null;
    }

    public function mimeType(string $path): ?string
    {
        $path = $this->normalize($path);
        $sample = substr($this->read($path), 0, 4096);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($sample);

        return is_string($mime) ? $mime : null;
    }

    public function isDirectory(string $path): bool
    {
        $path = $this->normalizeDirectory($path);
        if ($path === '') {
            return true;
        }

        return $this->call(function () use ($path): bool {
            $result = $this->client->listObjectsV2([
                'Bucket' => $this->bucket,
                'Prefix' => $this->key($path).'/',
                'MaxKeys' => 1,
            ]);
            $result->resolve();
            foreach ($result->getContents(true) as $object) {
                return true;
            }

            return false;
        });
    }

    public function write(string $path, string $contents, ?string $expectedVersion = null): StoreEntry
    {
        $path = $this->normalize($path);
        $this->assertWritable();

        return $this->put($path, $contents, $expectedVersion);
    }

    public function writeStream(string $path, $stream): StoreEntry
    {
        $path = $this->normalize($path);
        $this->assertWritable();

        // Buffered to a temp stream (memory up to 8 MB, then disk) to know size and SHA-256 first.
        $buffer = fopen('php://temp/maxmemory:'.(8 * 1024 * 1024), 'w+b');
        if ($buffer === false) {
            throw new \RuntimeException('Unable to open a temporary buffer.');
        }

        try {
            $hash = hash_init('sha256');
            $size = 0;
            while (! feof($stream)) {
                $chunk = fread($stream, 1024 * 1024);
                if ($chunk === false) {
                    throw new \RuntimeException("Unable to read the stream for [{$path}].");
                }
                if ($chunk === '') {
                    continue;
                }
                hash_update($hash, $chunk);
                fwrite($buffer, $chunk);
                $size += strlen($chunk);
            }
            rewind($buffer);

            return $this->put($path, $buffer, null, $size, hash_final($hash));
        } finally {
            fclose($buffer);
        }
    }

    public function delete(string $path, ?string $expectedVersion = null): void
    {
        $path = $this->normalize($path);
        $this->assertWritable();

        $this->withObjectLock($path, $expectedVersion !== null, function () use ($path, $expectedVersion): void {
            $current = $this->head($path);
            if ($current === null) {
                $this->stats[$path] = false;
                throw new VaultNoteNotFound($path);
            }
            if ($expectedVersion !== null && $expectedVersion !== $current->version) {
                throw new StoreVersionConflict($path, $expectedVersion, $current->version);
            }

            $this->deleteKey($this->key($path));
            $this->forget($path);
            $this->stats[$path] = false;
        });
    }

    public function move(string $from, string $to): void
    {
        $from = $this->normalize($from);
        $to = $this->normalize($to);
        $this->assertWritable();

        if ($this->head($from) === null) {
            throw new VaultNoteNotFound($from);
        }
        if ($this->head($to) !== null) {
            throw new \RuntimeException("Vault file [{$to}] already exists.");
        }

        $this->call(function () use ($from, $to): void {
            $this->client->copyObject([
                'Bucket' => $this->bucket,
                'Key' => $this->key($to),
                'CopySource' => $this->copySource($this->key($from)),
                'MetadataDirective' => 'COPY',
            ])->resolve();
        });
        $this->deleteKey($this->key($from));

        $this->forget($from);
        $this->forget($to);
        $this->stats[$from] = false;
    }

    public function list(string $prefix = '', ?string $cursor = null, int $limit = 1000): StorePage
    {
        $limit = max(1, $limit);
        $prefix = $this->normalizeDirectory($prefix);
        $listPrefix = $this->directoryKey($prefix);
        $startAfter = $cursor === null || $cursor === '' ? null : $this->key($cursor);
        if ($startAfter !== null && $prefix !== '' && strcmp($startAfter, $listPrefix) < 0) {
            $startAfter = null;
        }

        $entries = [];
        $more = false;
        $token = null;
        do {
            $page = $this->call(function () use ($listPrefix, $startAfter, $token, $limit) {
                $input = ['Bucket' => $this->bucket, 'MaxKeys' => min(1000, $limit + 1)];
                if ($listPrefix !== '') {
                    $input['Prefix'] = $listPrefix;
                }
                if ($token !== null) {
                    $input['ContinuationToken'] = $token;
                } elseif ($startAfter !== null) {
                    $input['StartAfter'] = $startAfter;
                }
                $result = $this->client->listObjectsV2($input);
                $result->resolve();

                $objects = [];
                foreach ($result->getContents(true) as $object) {
                    $objects[] = $object;
                }

                return [$objects, (bool) $result->getIsTruncated(), $result->getNextContinuationToken()];
            });
            [$objects, $truncated, $token] = $page;

            foreach ($objects as $object) {
                $key = (string) $object->getKey();
                if (str_ends_with($key, '/')) {
                    continue; // folder marker
                }
                $relative = $this->relative($key);
                if ($relative === null || preg_match('#(^|/)\.git(/|$)#i', $relative) === 1) {
                    continue;
                }
                if (count($entries) === $limit) {
                    $more = true;
                    break 2;
                }
                $entries[] = new StoreEntry(
                    $relative,
                    (int) $object->getSize(),
                    self::etag($object->getEtag()),
                    $object->getLastModified()?->getTimestamp(),
                );
            }
        } while ($truncated && $token !== null);

        $next = $more && $entries !== [] ? $entries[count($entries) - 1]->path : null;

        return new StorePage($entries, $next);
    }

    public function makeDirectory(string $path): void
    {
        $path = $this->normalizeDirectory($path);
        if ($path === '') {
            return; // the prefix itself needs no marker
        }
        $this->assertWritable();

        $this->call(function () use ($path): void {
            $this->client->putObject([
                'Bucket' => $this->bucket,
                'Key' => $this->key($path).'/',
                'Body' => '',
                'ContentType' => 'application/x-directory',
            ])->resolve();
        });
    }

    public function deleteDirectory(string $path): void
    {
        $path = $this->normalizeDirectory($path);
        $this->assertWritable();
        $listPrefix = $this->directoryKey($path);
        if ($listPrefix === '' && $this->prefix === '') {
            throw new \RuntimeException('Refusing to empty a whole bucket: the store has no workspace prefix.');
        }

        // Keys are collected first: deleting while paginating would shift the listing.
        do {
            $keys = $this->call(function () use ($listPrefix): array {
                $result = $this->client->listObjectsV2(['Bucket' => $this->bucket, 'Prefix' => $listPrefix, 'MaxKeys' => 1000]);
                $result->resolve();
                $keys = [];
                foreach ($result->getContents(true) as $object) {
                    $keys[] = (string) $object->getKey();
                }

                return $keys;
            });
            foreach ($keys as $key) {
                $this->deleteKey($key);
            }
        } while ($keys !== []);

        $this->bodies = [];
        $this->stats = [];
    }

    public function capabilities(): StoreCapabilities
    {
        return new StoreCapabilities(
            conditionalWrite: $this->conditionalWrites,
            emptyDirs: true,
            maxFileBytes: (int) config('jotter.s3.max_file_bytes', 5 * 1024 * 1024 * 1024),
            writeBehind: false,
            presignedUrls: true,
        );
    }

    /**
     * A short-lived GET URL with the response type and disposition forced by
     * the signature (the bucket's own metadata cannot change how it renders).
     */
    public function temporaryUrl(string $path, string $contentType, string $disposition, int $seconds = 60): string
    {
        $path = $this->normalize($path);

        return $this->call(fn (): string => $this->client->presign(new GetObjectRequest([
            'Bucket' => $this->bucket,
            'Key' => $this->key($path),
            'ResponseContentType' => $contentType,
            'ResponseContentDisposition' => $disposition,
        ]), new \DateTimeImmutable('+'.max(1, $seconds).' seconds')));
    }

    /** The SHA-256 the object was written with (`x-amz-meta-sha256`), null when absent. */
    public function storedSha256(string $path): ?string
    {
        $path = $this->normalize($path);

        return $this->call(function () use ($path): ?string {
            try {
                $result = $this->client->headObject(['Bucket' => $this->bucket, 'Key' => $this->key($path)]);
                $result->resolve();
                $metadata = array_change_key_case($result->getMetadata(), CASE_LOWER);

                return isset($metadata['sha256']) ? strtolower((string) $metadata['sha256']) : null;
            } catch (ClientException $exception) {
                if ($this->status($exception) === 404) {
                    return null;
                }
                throw $exception;
            }
        });
    }

    public static function etag(?string $etag): ?string
    {
        if ($etag === null) {
            return null;
        }
        $etag = trim($etag);
        if (str_starts_with($etag, 'W/')) {
            $etag = substr($etag, 2);
        }

        return trim($etag, '"') ?: null;
    }

    /**
     * @param  string|resource  $body
     */
    private function put(string $path, $body, ?string $expectedVersion, ?int $size = null, ?string $sha256 = null): StoreEntry
    {
        $size ??= strlen((string) $body);
        $sha256 ??= hash('sha256', (string) $body);
        $limit = $this->capabilities()->maxFileBytes;
        if ($limit !== null && $size > $limit) {
            throw new \App\Domain\Vault\Store\StoreFileTooLarge($path, $size, $limit);
        }

        $native = $this->conditionalWrites;

        // The named lock only matters where the provider cannot compare for us.
        return $this->withObjectLock($path, $expectedVersion !== null && ! $native, function () use ($path, $body, $expectedVersion, $size, $sha256, $native): StoreEntry {
            $input = [
                'Bucket' => $this->bucket,
                'Key' => $this->key($path),
                'Body' => $body,
                'ContentLength' => $size,
                'ContentType' => self::contentType($path),
                'Metadata' => ['sha256' => $sha256],
            ];

            if ($expectedVersion !== null) {
                if ($native) {
                    if ($expectedVersion === '') {
                        $input['IfNoneMatch'] = '*';
                    } else {
                        $input['IfMatch'] = '"'.$expectedVersion.'"';
                    }
                } else {
                    $current = $this->head($path)?->version;
                    if ($expectedVersion !== ($current ?? '')) {
                        throw new StoreVersionConflict($path, $expectedVersion === '' ? null : $expectedVersion, $current);
                    }
                }
            }

            $etag = $this->call(function () use ($input, $path, $expectedVersion): ?string {
                try {
                    $result = $this->client->putObject($input);
                    $result->resolve();

                    return self::etag($result->getEtag());
                } catch (ClientException $exception) {
                    // 412: the ETag moved; 409: a concurrent conditional write won the race.
                    if (in_array($this->status($exception), [409, 412], true) && $expectedVersion !== null) {
                        $this->forget($path);
                        throw new StoreVersionConflict($path, $expectedVersion === '' ? null : $expectedVersion, $this->head($path)?->version);
                    }
                    throw $exception;
                }
            });

            $this->forget($path);
            if ($etag === null) {
                $etag = $this->head($path)?->version;
            }
            if (is_string($body)) {
                $this->remember($path, $body);
            }
            $entry = new StoreEntry($path, $size, $etag, time());
            $this->stats[$path] = $entry;
            $this->markHealthy();

            return $entry;
        });
    }

    private function head(string $path): ?StoreEntry
    {
        return $this->call(function () use ($path): ?StoreEntry {
            try {
                $result = $this->client->headObject(['Bucket' => $this->bucket, 'Key' => $this->key($path)]);
                $result->resolve();

                return new StoreEntry(
                    $path,
                    (int) $result->getContentLength(),
                    self::etag($result->getEtag()),
                    $result->getLastModified()?->getTimestamp(),
                );
            } catch (ClientException $exception) {
                if ($this->status($exception) === 404) {
                    return null;
                }
                throw $exception;
            }
        });
    }

    private function deleteKey(string $key): void
    {
        $this->call(function () use ($key): void {
            try {
                $this->client->deleteObject(['Bucket' => $this->bucket, 'Key' => $key])->resolve();
            } catch (ClientException $exception) {
                if ($this->status($exception) !== 404) {
                    throw $exception;
                }
            }
        });
    }

    /**
     * Runs one S3 call and maps provider failures to the store's errors.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function call(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (NetworkException|ServerException|RedirectionException $exception) {
            $this->markDegraded();
            throw new StoreUnavailable(__('messages.vault_store_unavailable').' ('.$this->describe($exception).')', 0, $exception);
        } catch (ClientException $exception) {
            $status = $this->status($exception);
            if (in_array($status, [401, 403], true) || in_array($this->awsCode($exception), ['NoSuchBucket', 'InvalidAccessKeyId', 'SignatureDoesNotMatch', 'AccessDenied'], true)) {
                $this->factory?->markFailed($this->record, $this->describe($exception));
                throw new StoreUnavailable(__('messages.vault_store_unavailable').' ('.$this->describe($exception).')', 0, $exception);
            }
            throw $exception;
        } catch (\Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface $exception) {
            $this->markDegraded();
            throw new StoreUnavailable(__('messages.vault_store_unavailable').' ('.mb_substr($exception->getMessage(), 0, 200).')', 0, $exception);
        }
    }

    /**
     * Without native conditional writes, a MySQL named lock per object makes
     * "compare, then write" atomic among Jotter's own writers (plan §4.3, GCS).
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withObjectLock(string $path, bool $conditional, callable $callback): mixed
    {
        if (! $conditional || DB::getDriverName() !== 'mysql' && DB::getDriverName() !== 'mariadb') {
            return $callback();
        }

        $name = 'jotter-s3:'.substr(hash('sha256', $this->bucket.'/'.$this->key($path)), 0, 48);
        $locked = (int) (DB::selectOne('SELECT GET_LOCK(?, 10) AS l', [$name])->l ?? 0) === 1;
        if (! $locked) {
            throw new StoreUnavailable(__('messages.vault_store_unavailable').' (lock timeout)');
        }

        try {
            return $callback();
        } finally {
            DB::selectOne('SELECT RELEASE_LOCK(?) AS l', [$name]);
        }
    }

    private function assertWritable(): void
    {
        // An unsaved workspace is the connection test's context: it must run on a failed store.
        if ($this->record === null || $this->record->id === null || $this->workspace->id === null) {
            return;
        }
        $status = TenantVaultStore::query()->whereKey($this->record->id)->value('status');
        if ($status === TenantVaultStore::STATUS_FAILED) {
            throw new WorkspaceStorageReadOnly((int) $this->record->id, '', 'messages.workspace_storage_read_only_s3');
        }
    }

    private function markDegraded(): void
    {
        if ($this->workspace->id === null || $this->record === null) {
            return;
        }
        Workspace::query()->whereKey($this->workspace->id)->where('vault_store_id', $this->record->id)
            ->where('storage_state', 'active')->update(['storage_state' => 'degraded']);
        $this->workspace->storage_state = 'degraded';
    }

    /** A write that reached the bucket lifts the `degraded` state left by an outage. */
    private function markHealthy(): void
    {
        if ($this->workspace->id === null || $this->workspace->storage_state !== 'degraded' || $this->record === null) {
            return;
        }
        Workspace::query()->whereKey($this->workspace->id)->where('storage_state', 'degraded')->update(['storage_state' => 'active']);
        $this->workspace->storage_state = 'active';
    }

    private function status(HttpException $exception): int
    {
        try {
            return $exception->getResponse()->getStatusCode();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function awsCode(HttpException $exception): ?string
    {
        return method_exists($exception, 'getAwsCode') ? $exception->getAwsCode() : null;
    }

    /** Provider error without any credential: status, AWS code and message. */
    private function describe(\Throwable $exception): string
    {
        if ($exception instanceof HttpException) {
            $code = $this->awsCode($exception);
            $message = method_exists($exception, 'getAwsMessage') ? (string) $exception->getAwsMessage() : '';

            return trim('HTTP '.$this->status($exception).' '.($code ?? '').($message !== '' ? ': '.mb_substr($message, 0, 200) : ''));
        }

        return mb_substr($exception->getMessage(), 0, 200);
    }

    private function remember(string $path, string $body): void
    {
        if (strlen($body) > self::MEMO_MAX_BYTES) {
            return;
        }
        if (count($this->bodies) >= self::MEMO_MAX_ENTRIES) {
            array_shift($this->bodies);
        }
        $this->bodies[$path] = $body;
    }

    private function forget(string $path): void
    {
        unset($this->bodies[$path], $this->stats[$path]);
    }

    /**
     * @return resource
     */
    private function memoryStream(string $contents)
    {
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new \RuntimeException('Unable to open a temporary stream.');
        }
        fwrite($stream, $contents);
        rewind($stream);

        return $stream;
    }

    private function normalize(string $path): string
    {
        return VaultPathNormalizer::normalize($path, nfc: true, workspaceId: (int) $this->workspace->id);
    }

    private function normalizeDirectory(string $path): string
    {
        return VaultPathNormalizer::normalize($path, nfc: true, workspaceId: (int) $this->workspace->id, allowRoot: true);
    }

    private function key(string $path): string
    {
        return $this->prefix === '' ? $path : $this->prefix.'/'.$path;
    }

    /** Listing prefix of a directory: '' for the bucket root, else with a trailing slash. */
    private function directoryKey(string $directory): string
    {
        $key = $directory === '' ? $this->prefix : $this->key($directory);

        return $key === '' ? '' : $key.'/';
    }

    private function relative(string $key): ?string
    {
        if ($this->prefix === '') {
            return $key;
        }

        return str_starts_with($key, $this->prefix.'/') ? substr($key, strlen($this->prefix) + 1) : null;
    }

    private function copySource(string $key): string
    {
        return $this->bucket.'/'.implode('/', array_map('rawurlencode', explode('/', $key)));
    }

    private static function contentType(string $path): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($extension) {
            'md', 'markdown' => 'text/markdown; charset=utf-8',
            'txt' => 'text/plain; charset=utf-8',
            'json' => 'application/json',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'pdf' => 'application/pdf',
            default => 'application/octet-stream',
        };
    }
}
