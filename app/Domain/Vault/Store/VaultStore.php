<?php

namespace App\Domain\Vault\Store;

use App\Domain\Vault\Exceptions\PathTraversalRejected;
use App\Domain\Vault\Exceptions\VaultNoteNotFound;

/**
 * Where one workspace's vault files live: the local folder (default), an
 * S3-compatible bucket or a Git repository (docs/decisions.md, "Pluggable
 * vault stores"). Paths are workspace-relative, `/`-separated, and pass
 * VaultPathNormalizer; nothing outside VaultStoreResolver knows the driver.
 *
 * Every method that receives a path may throw PathTraversalRejected.
 */
interface VaultStore
{
    /** Driver name: `local`, `s3`, `github`. */
    public function driver(): string;

    /**
     * @throws VaultNoteNotFound when the file does not exist
     * @throws PathTraversalRejected
     */
    public function read(string $path): string;

    /**
     * A readable stream for large files (attachments). The caller closes it.
     *
     * @return resource
     *
     * @throws VaultNoteNotFound
     */
    public function readStream(string $path);

    /** Metadata of a file, or null when no file exists at $path (directories return null). */
    public function stat(string $path): ?StoreEntry;

    public function exists(string $path): bool;

    /** Best-effort MIME type of an existing file (content sniffing or stored Content-Type). */
    public function mimeType(string $path): ?string;

    /** True for an existing directory (or, on object stores, a non-empty prefix / folder marker). */
    public function isDirectory(string $path): bool;

    /**
     * Create or replace a file, creating parent directories as needed.
     *
     * @param  string|null  $expectedVersion  when given, the write fails with StoreVersionConflict
     *                                        unless the current version matches; `''` means "must not exist"
     *
     * @throws StoreVersionConflict
     */
    public function write(string $path, string $contents, ?string $expectedVersion = null): StoreEntry;

    /**
     * Create or replace a file from a readable stream (large attachments,
     * archive imports). The caller keeps ownership of $stream.
     *
     * @param  resource  $stream
     */
    public function writeStream(string $path, $stream): StoreEntry;

    /**
     * @throws VaultNoteNotFound
     * @throws StoreVersionConflict
     */
    public function delete(string $path, ?string $expectedVersion = null): void;

    /**
     * Move a file. The destination must not exist.
     *
     * @throws VaultNoteNotFound
     */
    public function move(string $from, string $to): void;

    /**
     * Files (never directories) under $prefix, recursively, in byte order of
     * their path, starting after $cursor. Bounded so cron commands can resume.
     * Listings may leave StoreEntry::$version null when the driver cannot give
     * it cheaply; stat() always fills it.
     */
    public function list(string $prefix = '', ?string $cursor = null, int $limit = 1000): StorePage;

    public function makeDirectory(string $path): void;

    /** Remove a directory and everything below it ('' = the whole workspace vault). */
    public function deleteDirectory(string $path): void;

    public function capabilities(): StoreCapabilities;
}
