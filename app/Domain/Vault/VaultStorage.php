<?php

namespace App\Domain\Vault;

use App\Models\Note;
use App\Models\Workspace;

/**
 * Path-safe read/write for workspace Markdown vaults. Disk files are source of truth;
 * MySQL is updated as a rebuildable projection on every successful write.
 */
final class VaultStorage
{
    public function __construct(
        private readonly VaultPathGuard $paths = new VaultPathGuard,
        private readonly NoteProjector $projector = new NoteProjector,
    ) {}

    public function read(Workspace $workspace, string $relativePath): MarkdownDocument
    {
        $absolute = $this->paths->resolve($workspace, $relativePath, mustExist: true);

        $raw = file_get_contents($absolute);
        if ($raw === false) {
            throw new \RuntimeException("Unable to read vault note [{$relativePath}].");
        }

        return MarkdownDocument::parse($raw, $this->fallbackTitle($relativePath));
    }

    public function write(Workspace $workspace, string $relativePath, string $contents): Note
    {
        $absolute = $this->paths->resolve($workspace, $relativePath, mustExist: false);
        $relative = $this->paths->toRelative($workspace, $absolute);

        $this->ensureParentDirectory($workspace, $absolute);

        // Re-resolve after parents exist so symlink escapes are caught before write.
        $absolute = $this->paths->resolve($workspace, $relative, mustExist: false);

        $written = file_put_contents($absolute, $contents, LOCK_EX);
        if ($written === false) {
            throw new \RuntimeException("Unable to write vault note [{$relative}].");
        }

        $document = MarkdownDocument::parse($contents, $this->fallbackTitle($relative));

        return $this->projector->project($workspace, $relative, $document);
    }

    /**
     * @param  array<string, mixed>  $frontmatter
     */
    public function writeDocument(Workspace $workspace, string $relativePath, array $frontmatter, string $body): Note
    {
        return $this->write(
            $workspace,
            $relativePath,
            MarkdownDocument::compose($frontmatter, $body),
        );
    }

    public function exists(Workspace $workspace, string $relativePath): bool
    {
        try {
            $absolute = $this->paths->resolve($workspace, $relativePath, mustExist: false);
        } catch (\Throwable) {
            return false;
        }

        return is_file($absolute);
    }

    private function ensureParentDirectory(Workspace $workspace, string $absolute): void
    {
        $root = $this->paths->ensureVaultRoot($workspace);
        $parent = dirname($absolute);

        $rootNormalized = rtrim(str_replace('\\', '/', $root), '/');
        $parentNormalized = rtrim(str_replace('\\', '/', $parent), '/');

        if ($parentNormalized !== $rootNormalized && ! str_starts_with($parentNormalized, $rootNormalized.'/')) {
            throw new \RuntimeException('Refusing to create directories outside the vault root.');
        }

        if (is_dir($parent)) {
            $realParent = realpath($parent);
            if ($realParent === false) {
                throw new \RuntimeException('Vault parent directory could not be canonicalized.');
            }

            $realNormalized = str_replace('\\', '/', $realParent);
            if ($realNormalized !== $rootNormalized && ! str_starts_with($realNormalized, $rootNormalized.'/')) {
                throw new \RuntimeException('Refusing to use a vault parent outside the workspace root.');
            }

            return;
        }

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

        $realParent = realpath($parent);
        if ($realParent === false) {
            throw new \RuntimeException('Vault parent directory could not be canonicalized.');
        }

        $realNormalized = str_replace('\\', '/', $realParent);
        if ($realNormalized !== $rootNormalized && ! str_starts_with($realNormalized, $rootNormalized.'/')) {
            throw new \RuntimeException('Refusing to use a vault parent outside the workspace root.');
        }
    }

    private function fallbackTitle(string $relativePath): string
    {
        $base = basename(str_replace('\\', '/', $relativePath));
        if (str_ends_with(strtolower($base), '.md')) {
            $base = substr($base, 0, -3);
        }

        return $base === '' ? 'Untitled' : $base;
    }
}
