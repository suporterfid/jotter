<?php

namespace App\Domain\Vault;

use App\Domain\Vault\Store\VaultStore;
use App\Domain\Vault\Store\VaultStoreResolver;
use App\Models\Attachment;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;

final class AttachmentStorage
{
    public function __construct(
        private readonly VaultPathGuard $paths = new VaultPathGuard,
        private readonly ?VaultStoreResolver $stores = null,
    ) {}

    public function store(Workspace $workspace): VaultStore
    {
        return ($this->stores ?? app(VaultStoreResolver::class))->for($workspace);
    }

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

        $relative = $this->paths->normalize($workspace, $relativePath, mustBeMarkdown: false);
        $store = $this->store($workspace);

        // Storage quota (#390): only growth is refused.
        $delta = strlen($contents) - ($store->stat($relative)?->size ?? 0);
        $quota = app(\App\Domain\Quota\WorkspaceQuota::class);
        $quota->assertCanGrow($workspace, $delta);

        $size = $store->write($relative, $contents)->size;
        $quota->recordDelta($workspace, $delta);

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

        (new \App\Domain\Audit\AuditRecorder)->record(
            \App\Domain\Audit\AuditEvent::ATTACHMENT_CREATED,
            $workspace->tenant_id,
            $workspace->id,
            (string) (auth()->id() ?? 'system'),
            [
                'attachment_id' => $attachment->id,
                'path' => $relative,
                'mime' => $mime,
                'size' => $size,
            ]
        );

        return $attachment;
    }

    /**
     * Resolve attachment file info. The path is validated and must exist in the store.
     *
     * @return array{0: string, 1: string, 2: int} [relativePath, mime, size]
     */
    public function readAttachmentInfo(Workspace $workspace, string|int $idOrPath): array
    {
        $attachment = $this->findAttachment($workspace, $idOrPath);
        $relativePath = $attachment ? $attachment->path : (string) $idOrPath;

        $relative = $this->paths->normalize($workspace, $relativePath, mustBeMarkdown: false);
        $store = $this->store($workspace);
        $stat = $store->stat($relative);
        if ($stat === null) {
            throw new Exceptions\VaultNoteNotFound($relativePath);
        }

        $mime = $attachment?->mime ?: ($store->mimeType($relative) ?: 'application/octet-stream');
        $size = $attachment?->size ?: $stat->size;

        return [$relative, $mime, $size];
    }

    public function deleteAttachment(Workspace $workspace, string|int $idOrPath): void
    {
        $attachment = $this->findAttachment($workspace, $idOrPath);
        $relativePath = $attachment ? $attachment->path : (string) $idOrPath;

        try {
            $relative = $this->paths->normalize($workspace, $relativePath, mustBeMarkdown: false);
            $store = $this->store($workspace);
            if ($store->exists($relative)) {
                $store->delete($relative);
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

        (new \App\Domain\Audit\AuditRecorder)->record(
            \App\Domain\Audit\AuditEvent::ATTACHMENT_DELETED,
            $workspace->tenant_id,
            $workspace->id,
            (string) (auth()->id() ?? 'system'),
            [
                'path' => $relativePath,
            ]
        );
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
}
