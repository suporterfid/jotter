<?php

namespace App\Domain\Vault;

use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Vault\Exceptions\PathTraversalRejected;
use App\Models\Workspace;

final class VaultRootGuard
{
    public function __construct(
        private readonly AuditRecorder $auditRecorder = new AuditRecorder,
    ) {}

    /**
     * Validate that a proposed workspace vault_path is safe, strictly confined
     * within the configured base path, and free of nesting collisions.
     *
     * @throws PathTraversalRejected
     */
    public function validate(string $proposedPath, ?int $ignoreWorkspaceId = null): string
    {
        $basePath = config('vault.base_path') ?: storage_path('app/vaults');
        @mkdir($basePath, 0755, true);

        $realBase = realpath($basePath);
        if ($realBase === false) {
            $realBase = rtrim($basePath, '/');
        }

        // Canonicalize proposed path
        $canonicalProposed = $this->canonicalize($proposedPath);

        // 1. Base directory confinement check
        if (! $this->isUnderBase($realBase, $canonicalProposed)) {
            $this->auditRecorder->record(
                event: AuditEvent::VAULT_ROOT_REJECTED,
                metadata: ['reason' => "Vault path outside base directory: [{$proposedPath}]"],
            );
            throw new PathTraversalRejected(
                workspaceId: $ignoreWorkspaceId ?? 0,
                attemptedPath: $proposedPath,
                message: "Vault path must be inside the configured base directory [{$realBase}].",
            );
        }

        // 2. Symlink escape check
        if (is_link($proposedPath)) {
            $target = realpath($proposedPath);
            if ($target === false || ! $this->isUnderBase($realBase, $target)) {
                $this->auditRecorder->record(
                    event: AuditEvent::VAULT_ROOT_REJECTED,
                    metadata: ['reason' => "Vault path symlink escapes base directory: [{$proposedPath}]"],
                );
                throw new PathTraversalRejected(
                    workspaceId: $ignoreWorkspaceId ?? 0,
                    attemptedPath: $proposedPath,
                    message: "Symlinked vault paths escaping the base directory are rejected.",
                );
            }
        }

        // 3. Vault nesting & collision check against existing workspaces
        $workspaces = Workspace::query()
            ->when($ignoreWorkspaceId, fn ($q) => $q->where('id', '!=', $ignoreWorkspaceId))
            ->get();

        foreach ($workspaces as $existing) {
            $canonicalExisting = $this->canonicalize($existing->vault_path);

            if ($canonicalProposed === $canonicalExisting) {
                $this->auditRecorder->record(
                    event: AuditEvent::VAULT_ROOT_REJECTED,
                    metadata: ['reason' => "Vault path collision with workspace [{$existing->id}]: [{$proposedPath}]"],
                );
                throw new PathTraversalRejected(
                    workspaceId: $ignoreWorkspaceId ?? 0,
                    attemptedPath: $proposedPath,
                    message: "Vault path collides with an existing workspace vault.",
                );
            }

            // Check if proposed path is inside existing vault OR existing vault is inside proposed path
            if (str_starts_with($canonicalProposed, $canonicalExisting.'/') || str_starts_with($canonicalExisting, $canonicalProposed.'/')) {
                $this->auditRecorder->record(
                    event: AuditEvent::VAULT_ROOT_REJECTED,
                    metadata: ['reason' => "Vault path nesting conflict with workspace [{$existing->id}]: [{$proposedPath}]"],
                );
                throw new PathTraversalRejected(
                    workspaceId: $ignoreWorkspaceId ?? 0,
                    attemptedPath: $proposedPath,
                    message: "Vault path cannot nest inside or contain an existing workspace vault.",
                );
            }
        }

        return $canonicalProposed;
    }

    private function canonicalize(string $path): string
    {
        $real = realpath($path);
        if ($real !== false) {
            return rtrim($real, '/');
        }

        // Normalize non-existent candidate path
        $parts = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($parts);
            } else {
                $parts[] = $segment;
            }
        }

        return (str_starts_with($path, '/') ? '/' : '').implode('/', $parts);
    }

    private function isUnderBase(string $base, string $path): bool
    {
        return $path === $base || str_starts_with($path, $base.'/');
    }
}
