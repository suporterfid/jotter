<?php

namespace App\Domain\Vault;

use App\Domain\Vault\Exceptions\PathTraversalRejected;
use App\Domain\Vault\Exceptions\VaultNoteNotFound;
use App\Models\AuditLog;
use App\Models\Workspace;

/**
 * Canonicalizes in-vault note paths and rejects anything that escapes the workspace root (§8/S2).
 */
final class VaultPathGuard
{
    public const AUDIT_EVENT = 'vault.path_traversal_rejected';

    /**
     * Resolve a relative in-vault path to an absolute filesystem path under the workspace vault root.
     *
     * Traversal attempts are rejected using path-string rules before any access to the candidate path.
     *
     * @throws PathTraversalRejected
     * @throws VaultNoteNotFound
     */
    public function resolve(Workspace $workspace, string $relativePath, bool $mustExist = false): string
    {
        // Validate the candidate path string before any filesystem access (§7.1 / §8 S2).
        $relative = $this->assertSafeRelativePath($workspace, $relativePath);
        $root = $this->canonicalVaultRoot($workspace);
        $absolute = $this->join($root, $relative);

        if (! $this->isUnderVaultRoot($root, $absolute)) {
            $this->reject($workspace, $relativePath);
        }

        if ($mustExist) {
            $real = realpath($absolute);
            if ($real === false) {
                throw new VaultNoteNotFound($relativePath);
            }

            if (! $this->isUnderVaultRoot($root, $real)) {
                $this->reject($workspace, $relativePath);
            }

            return $real;
        }

        if (is_link($absolute) || file_exists($absolute)) {
            $real = realpath($absolute);
            if ($real === false || ! $this->isUnderVaultRoot($root, $real)) {
                $this->reject($workspace, $relativePath);
            }

            return $real;
        }

        $parent = dirname($absolute);
        if (is_dir($parent) || is_link($parent)) {
            $realParent = realpath($parent);
            // Parent may be the vault root itself for root-level notes.
            if ($realParent === false || ! $this->isUnderVaultRoot($root, $realParent, allowRoot: true)) {
                $this->reject($workspace, $relativePath);
            }

            return $realParent.DIRECTORY_SEPARATOR.basename($absolute);
        }

        return $absolute;
    }

    /**
     * Ensure the vault root exists on disk and return its canonical absolute path.
     */
    public function ensureVaultRoot(Workspace $workspace): string
    {
        $configured = $this->normalizeConfiguredRoot((string) $workspace->vault_path);

        if (! is_dir($configured) && ! file_exists($configured)) {
            if (! mkdir($configured, 0755, true) && ! is_dir($configured)) {
                throw new \RuntimeException("Unable to create vault root [{$configured}].");
            }
        }

        $real = realpath($configured);
        if ($real === false || ! is_dir($real)) {
            throw new \RuntimeException("Vault root [{$configured}] is not a usable directory.");
        }

        return $real;
    }

    public function toRelative(Workspace $workspace, string $absolutePath): string
    {
        $root = $this->canonicalVaultRoot($workspace);
        $absolute = str_replace('\\', '/', $absolutePath);
        $rootNormalized = str_replace('\\', '/', $root);

        if (! $this->isUnderVaultRoot($rootNormalized, $absolute)) {
            $this->reject($workspace, $absolutePath);
        }

        $relative = substr($absolute, strlen(rtrim($rootNormalized, '/')));
        $relative = ltrim(str_replace('\\', '/', $relative), '/');

        return $this->assertSafeRelativePath($workspace, $relative);
    }

    private function canonicalVaultRoot(Workspace $workspace): string
    {
        return $this->ensureVaultRoot($workspace);
    }

    private function assertSafeRelativePath(Workspace $workspace, string $relativePath): string
    {
        $path = str_replace('\\', '/', $relativePath);
        $path = trim($path);

        if ($path === '' || str_contains($path, "\0")) {
            $this->reject($workspace, $relativePath);
        }

        if ($this->looksAbsolute($path)) {
            $this->reject($workspace, $relativePath);
        }

        $segments = explode('/', $path);
        $normalized = [];

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                $this->reject($workspace, $relativePath);
            }

            if ($segment === '..') {
                $this->reject($workspace, $relativePath);
            }

            if (str_contains($segment, ':')) {
                $this->reject($workspace, $relativePath);
            }

            $normalized[] = $segment;
        }

        $normalizedPath = implode('/', $normalized);

        if (! str_ends_with(strtolower($normalizedPath), '.md')) {
            $this->reject($workspace, $relativePath, 'Vault note paths must end with .md.');
        }

        return $normalizedPath;
    }

    private function looksAbsolute(string $path): bool
    {
        if (str_starts_with($path, '/')) {
            return true;
        }

        if (str_starts_with($path, '~')) {
            return true;
        }

        // Windows drive or UNC
        if (preg_match('#^[a-zA-Z]:#', $path) === 1) {
            return true;
        }

        return str_starts_with($path, '//');
    }

    private function join(string $root, string $relative): string
    {
        return rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }

    /**
     * True when $absolute is inside the vault root. When $allowRoot is true, the root
     * directory itself is accepted (needed for parent-dir checks of root-level notes).
     */
    private function isUnderVaultRoot(string $root, string $absolute, bool $allowRoot = false): bool
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $absolute = rtrim(str_replace('\\', '/', $absolute), '/');

        if ($absolute === $root) {
            return $allowRoot;
        }

        return str_starts_with($absolute, $root.'/');
    }

    private function normalizeConfiguredRoot(string $vaultPath): string
    {
        $path = str_replace('\\', '/', trim($vaultPath));

        if ($path === '' || str_contains($path, "\0")) {
            throw new \InvalidArgumentException('Workspace vault_path is empty or invalid.');
        }

        if (! $this->looksAbsolute($path) && ! str_starts_with($vaultPath, DIRECTORY_SEPARATOR)) {
            // Relative configured roots are resolved from the application base path.
            $path = base_path($path);
        }

        return $path;
    }

    private function reject(Workspace $workspace, string $attemptedPath, ?string $message = null): never
    {
        AuditLog::query()->create([
            'tenant_id' => $workspace->tenant_id,
            'workspace_id' => $workspace->id,
            'actor_subject_id' => null,
            'event' => self::AUDIT_EVENT,
            'metadata' => [
                // TODO(spec: Before PR7, finalize audit event vocabulary and metadata redaction.)
                'attempted_path' => $attemptedPath,
            ],
            'ip_address' => null,
        ]);

        throw new PathTraversalRejected(
            (int) $workspace->id,
            $attemptedPath,
            $message ?? 'Path resolves outside the workspace vault root.',
        );
    }
}
