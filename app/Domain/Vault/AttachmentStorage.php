<?php

namespace App\Domain\Vault;

use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;

final class AttachmentStorage
{
    public function __construct(
        private readonly VaultPathGuard $paths = new VaultPathGuard,
    ) {}

    /**
     * Write an attachment to the vault storage and record it in the database.
     */
    public function writeAttachment(
        Workspace $workspace,
        UploadedFile|string $fileOrContents,
        ?string $relativePath = null,
        ?string $clientMime = null,
    ): Attachment {
        $filename = null;
        $contents = null;

        if ($fileOrContents instanceof UploadedFile) {
            $filename = $fileOrContents->getClientOriginalName();
            $mime = $clientMime ?: ($fileOrContents->getClientMimeType() ?: $fileOrContents->getMimeType() ?: 'application/octet-stream');
            $contents = file_get_contents($fileOrContents->getRealPath());
        } else {
            $contents = $fileOrContents;
            $mime = $clientMime ?: 'application/octet-stream';
        }

        if ($contents === false || $contents === null) {
            throw new \RuntimeException('Failed to read attachment contents.');
        }

        if (empty($relativePath)) {
            $safeName = $filename ? $this->sanitizeFilename($filename) : ('file_' . uniqid() . '.bin');
            $relativePath = '_resources/' . $safeName;
        } elseif (! str_starts_with($relativePath, '_resources/')) {
            if (! str_contains($relativePath, '/')) {
                $relativePath = '_resources/' . $relativePath;
            }
        }

        $absolute = $this->paths->resolve($workspace, $relativePath, mustExist: false, mustBeMarkdown: false);
        $relative = $this->paths->toRelative($workspace, $absolute, mustBeMarkdown: false);

        $this->ensureParentDirectory($workspace, $absolute);

        $written = file_put_contents($absolute, $contents, LOCK_EX);
        if ($written === false) {
            throw new \RuntimeException("Unable to write vault attachment [{$relative}].");
        }

        $size = filesize($absolute);
        if ($size === false) {
            $size = strlen($contents);
        }

        /** @var Attachment $attachment */
        $attachment = Attachment::query()->updateOrCreate(
            [
                'workspace_id' => $workspace->id,
                'path' => $relative,
            ],
            [
                'mime' => $mime,
                'size' => $size,
            ],
        );

        AuditLog::query()->create([
            'tenant_id' => $workspace->tenant_id,
            'workspace_id' => $workspace->id,
            'event' => 'attachment.created',
            'actor_type' => 'user',
            'actor_id' => (string) (auth()->id() ?? 'system'),
            'metadata' => [
                'attachment_id' => $attachment->id,
                'path' => $relative,
                'mime' => $mime,
                'size' => $size,
            ],
        ]);

        return $attachment;
    }

    /**
     * Resolve and read attachment file info.
     *
     * @return array{0: string, 1: string, 2: int} [absolutePath, mime, size]
     */
    public function readAttachmentInfo(Workspace $workspace, string|int $idOrPath): array
    {
        $attachment = $this->findAttachment($workspace, $idOrPath);
        $relativePath = $attachment ? $attachment->path : (string) $idOrPath;

        $absolute = $this->paths->resolve($workspace, $relativePath, mustExist: true, mustBeMarkdown: false);

        $mime = $attachment?->mime ?: (mime_content_type($absolute) ?: 'application/octet-stream');
        $size = $attachment?->size ?: (filesize($absolute) ?: 0);

        return [$absolute, $mime, $size];
    }

    public function deleteAttachment(Workspace $workspace, string|int $idOrPath): void
    {
        $attachment = $this->findAttachment($workspace, $idOrPath);
        $relativePath = $attachment ? $attachment->path : (string) $idOrPath;

        try {
            $absolute = $this->paths->resolve($workspace, $relativePath, mustExist: true, mustBeMarkdown: false);
            if (is_file($absolute)) {
                unlink($absolute);
            }
        } catch (\Throwable) {
            // File might already be missing on disk
        }

        if ($attachment) {
            $attachment->delete();
        } else {
            Attachment::query()
                ->where('workspace_id', $workspace->id)
                ->where('path', $relativePath)
                ->delete();
        }

        AuditLog::query()->create([
            'tenant_id' => $workspace->tenant_id,
            'workspace_id' => $workspace->id,
            'event' => 'attachment.deleted',
            'actor_type' => 'user',
            'actor_id' => (string) (auth()->id() ?? 'system'),
            'metadata' => [
                'path' => $relativePath,
            ],
        ]);
    }

    public function findAttachment(Workspace $workspace, string|int $idOrPath): ?Attachment
    {
        if (is_numeric($idOrPath)) {
            return Attachment::query()
                ->where('workspace_id', $workspace->id)
                ->where('id', (int) $idOrPath)
                ->first();
        }

        return Attachment::query()
            ->where('workspace_id', $workspace->id)
            ->where('path', (string) $idOrPath)
            ->first();
    }

    private function sanitizeFilename(string $filename): string
    {
        $basename = basename(str_replace('\\', '/', $filename));
        $clean = preg_replace('/[^a-zA-Z0-9_\.\-]/', '_', $basename);
        return $clean ?: ('attachment_' . time());
    }

    private function ensureParentDirectory(Workspace $workspace, string $absolute): void
    {
        $parent = dirname($absolute);
        if (! is_dir($parent)) {
            if (! mkdir($parent, 0755, true) && ! is_dir($parent)) {
                throw new \RuntimeException("Unable to create attachment directory for [{$absolute}].");
            }
        }
    }
}
