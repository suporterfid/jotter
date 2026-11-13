<?php

namespace App\Domain\Vault\Store;

use App\Domain\Vault\Exceptions\PathTraversalRejected;
use App\Domain\Vault\Exceptions\VaultNoteNotFound;
use App\Domain\Vault\VaultPathGuard;
use App\Models\Workspace;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The default store: a directory on the server that PHP reads and writes
 * directly. Keeps every realpath/symlink check of VaultPathGuard; rejections
 * are audited against the owning workspace.
 *
 * This class (with VaultFileResponse) is the only place that touches vault
 * files on disk; the rest of the app goes through VaultStore.
 */
final class LocalVaultStore implements VaultStore
{
    public function __construct(
        private readonly string $root,
        private readonly Workspace $workspace,
        private readonly VaultPathGuard $guard = new VaultPathGuard,
    ) {}

    public function driver(): string
    {
        return 'local';
    }

    /** Canonical absolute root, created on first use. */
    public function root(): string
    {
        return $this->guard->ensureRoot($this->root);
    }

    /**
     * Absolute path of an existing file, for responses that serve local files
     * directly (Range support). Only VaultFileResponse should need it.
     */
    public function absolutePath(string $path): string
    {
        return $this->resolve($path, mustExist: true);
    }

    public function read(string $path): string
    {
        $absolute = $this->resolve($path, mustExist: true);

        $raw = is_file($absolute) ? file_get_contents($absolute) : false;
        if ($raw === false) {
            throw new \RuntimeException("Unable to read vault file [{$path}].");
        }

        return $raw;
    }

    public function readStream(string $path)
    {
        $absolute = $this->resolve($path, mustExist: true);
        $handle = is_file($absolute) ? fopen($absolute, 'rb') : false;
        if ($handle === false) {
            throw new \RuntimeException("Unable to open vault file [{$path}].");
        }

        return $handle;
    }

    public function stat(string $path): ?StoreEntry
    {
        try {
            $absolute = $this->resolve($path, mustExist: false);
        } catch (VaultNoteNotFound) {
            return null;
        }

        clearstatcache(true, $absolute);
        if (! is_file($absolute)) {
            return null;
        }

        return new StoreEntry(
            $this->relative($path),
            (int) filesize($absolute),
            hash_file('sha256', $absolute) ?: null,
            filemtime($absolute) ?: null,
        );
    }

    public function exists(string $path): bool
    {
        try {
            return is_file($this->resolve($path, mustExist: false));
        } catch (PathTraversalRejected|VaultNoteNotFound) {
            return false;
        }
    }

    public function isDirectory(string $path): bool
    {
        if (trim($path, '/') === '') {
            return is_dir($this->root());
        }

        try {
            return is_dir($this->resolve($path, mustExist: false));
        } catch (VaultNoteNotFound) {
            return false;
        }
    }

    public function mimeType(string $path): ?string
    {
        $absolute = $this->resolve($path, mustExist: true);

        return mime_content_type($absolute) ?: null;
    }

    public function write(string $path, string $contents, ?string $expectedVersion = null): StoreEntry
    {
        $relative = $this->relative($path);
        $absolute = $this->resolve($relative, mustExist: false);
        $this->ensureParentDirectory($absolute);

        // Re-resolve after parents exist so symlink escapes are caught before write.
        $absolute = $this->resolve($relative, mustExist: false);

        if ($expectedVersion !== null) {
            $this->assertVersion($relative, $absolute, $expectedVersion);
        }

        if (file_put_contents($absolute, $contents, LOCK_EX) === false) {
            throw new \RuntimeException("Unable to write vault file [{$relative}].");
        }
        clearstatcache(true, $absolute);

        return new StoreEntry($relative, strlen($contents), hash('sha256', $contents), filemtime($absolute) ?: null);
    }

    public function writeStream(string $path, $stream): StoreEntry
    {
        $relative = $this->relative($path);
        $absolute = $this->resolve($relative, mustExist: false);
        $this->ensureParentDirectory($absolute);
        $absolute = $this->resolve($relative, mustExist: false);

        $out = fopen($absolute, 'wb');
        if ($out === false) {
            throw new \RuntimeException("Unable to write vault file [{$relative}].");
        }
        try {
            if (stream_copy_to_stream($stream, $out) === false) {
                throw new \RuntimeException("Unable to write vault file [{$relative}].");
            }
        } finally {
            fclose($out);
        }
        clearstatcache(true, $absolute);

        return new StoreEntry($relative, (int) filesize($absolute), hash_file('sha256', $absolute) ?: null, filemtime($absolute) ?: null);
    }

    public function delete(string $path, ?string $expectedVersion = null): void
    {
        $absolute = $this->resolve($path, mustExist: true);

        if ($expectedVersion !== null) {
            $this->assertVersion($this->relative($path), $absolute, $expectedVersion);
        }

        if (! is_file($absolute) || ! unlink($absolute)) {
            throw new \RuntimeException("Unable to delete vault file [{$path}].");
        }
    }

    public function move(string $from, string $to): void
    {
        $fromAbsolute = $this->resolve($from, mustExist: true);
        $toAbsolute = $this->resolve($to, mustExist: false);

        if (file_exists($toAbsolute)) {
            throw new \RuntimeException("Vault destination [{$to}] already exists.");
        }

        $this->ensureParentDirectory($toAbsolute);
        $toAbsolute = $this->resolve($to, mustExist: false);

        if (! rename($fromAbsolute, $toAbsolute)) {
            throw new \RuntimeException("Unable to move vault file from [{$from}] to [{$to}].");
        }
    }

    public function list(string $prefix = '', ?string $cursor = null, int $limit = 1000): StorePage
    {
        $limit = max(1, $limit);
        if (! is_dir($this->root)) {
            // Listing never creates the vault root (quota measurement, doctor).
            return new StorePage([]);
        }
        $root = $this->root();
        $rootNormalized = rtrim(str_replace('\\', '/', $root), '/');
        $prefix = trim($prefix, '/');
        $start = $prefix === '' ? $root : $root.'/'.$prefix;

        if ($prefix !== '') {
            $this->relativeDirectory($prefix);
        }
        if (! is_dir($start)) {
            return new StorePage([]);
        }

        $paths = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($start, FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO),
            RecursiveIteratorIterator::LEAVES_ONLY,
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $relative = ltrim(substr(str_replace('\\', '/', $file->getPathname()), strlen($rootNormalized)), '/');
            if ($cursor !== null && strcmp($relative, $cursor) <= 0) {
                continue;
            }
            if (preg_match('#(^|/)\.git(/|$)#i', $relative) === 1) {
                continue;
            }

            // Skip symlink escapes: only files whose realpath stays inside the root.
            $real = realpath($file->getPathname());
            if ($real === false || ! str_starts_with(str_replace('\\', '/', $real), $rootNormalized.'/')) {
                continue;
            }

            $paths[$relative] = $file;
        }

        uksort($paths, 'strcmp');
        $page = array_slice($paths, 0, $limit, true);
        $entries = [];
        foreach ($page as $relative => $file) {
            $entries[] = new StoreEntry((string) $relative, (int) $file->getSize(), null, $file->getMTime() ?: null);
        }

        $next = count($paths) > $limit && $entries !== [] ? $entries[count($entries) - 1]->path : null;

        return new StorePage($entries, $next);
    }

    public function makeDirectory(string $path): void
    {
        $relative = $this->relativeDirectory($path);
        if ($relative === '') {
            $this->root();

            return;
        }

        $absolute = $this->resolve($relative, mustExist: false);
        if (! is_dir($absolute)) {
            $this->ensureParentDirectory($absolute.'/.');
        }
    }

    public function deleteDirectory(string $path): void
    {
        $relative = $this->relativeDirectory($path);
        $root = $this->root();
        $target = $relative === '' ? $root : $this->resolve($relative, mustExist: false);

        if (! is_dir($target) || is_link($target)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() && ! $item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($target);
    }

    public function capabilities(): StoreCapabilities
    {
        return new StoreCapabilities(
            conditionalWrite: true,
            emptyDirs: true,
            maxFileBytes: null,
            writeBehind: false,
            presignedUrls: false,
        );
    }

    private function relative(string $path): string
    {
        $relative = $this->guard->normalize($this->workspace, $path, mustBeMarkdown: false);

        return VaultPathNormalizer::normalize($relative, nfc: false, workspaceId: (int) $this->workspace->id);
    }

    private function relativeDirectory(string $path): string
    {
        if (trim($path, '/') === '') {
            return '';
        }

        return $this->relative(trim($path, '/'));
    }

    private function resolve(string $path, bool $mustExist): string
    {
        return $this->guard->resolveUnder($this->root, $this->workspace, $this->relative($path), $mustExist, mustBeMarkdown: false);
    }

    private function assertVersion(string $relative, string $absolute, string $expectedVersion): void
    {
        clearstatcache(true, $absolute);
        $current = is_file($absolute) ? (hash_file('sha256', $absolute) ?: null) : null;
        $matches = $expectedVersion === '' ? $current === null : ($current !== null && hash_equals($current, $expectedVersion));

        if (! $matches) {
            throw new StoreVersionConflict($relative, $expectedVersion, $current);
        }
    }

    private function ensureParentDirectory(string $absolute): void
    {
        $root = $this->root();
        $parent = dirname($absolute);

        $rootNormalized = rtrim(str_replace('\\', '/', $root), '/');
        $parentNormalized = rtrim(str_replace('\\', '/', $parent), '/');

        if ($parentNormalized !== $rootNormalized && ! str_starts_with($parentNormalized, $rootNormalized.'/')) {
            throw new \RuntimeException('Refusing to create directories outside the vault root.');
        }

        if (! is_dir($parent)) {
            $relativeParent = trim(substr($parentNormalized, strlen($rootNormalized)), '/');
            if ($relativeParent !== '') {
                foreach (explode('/', $relativeParent) as $segment) {
                    if ($segment === '' || $segment === '.' || $segment === '..' || str_contains($segment, ':')) {
                        throw new \RuntimeException('Refusing to create unsafe vault directories.');
                    }
                }
            }

            if (! mkdir($parent, 0755, true) && ! is_dir($parent)) {
                throw new \RuntimeException("Unable to create vault directory for [{$absolute}].");
            }
        }

        $realParent = realpath($parent);
        if ($realParent === false) {
            throw new \RuntimeException('Vault parent directory could not be canonicalized.');
        }

        $realNormalized = str_replace('\\', '/', $realParent);
        if ($realNormalized !== $rootNormalized && ! str_starts_with($realNormalized, $rootNormalized.'/')) {
            throw new \RuntimeException('Refusing to use a vault parent outside the workspace root.');
        }
    }
}
