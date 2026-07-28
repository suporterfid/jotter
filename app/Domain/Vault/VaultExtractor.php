<?php

namespace App\Domain\Vault;

use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Vault\Exceptions\PathTraversalRejected;
use App\Models\Workspace;
use ZipArchive;

final class VaultExtractor
{
    public const MAX_ENTRIES = 10000;

    public const MAX_TOTAL_BYTES = 524288000; // 500 MB

    public function __construct(
        private readonly VaultPathGuard $pathGuard = new VaultPathGuard,
        private readonly AuditRecorder $recorder = new AuditRecorder,
    ) {}

    /**
     * @return array{extracted: list<string>, skipped: list<string>, errors: list<string>}
     */
    public function extract(Workspace $workspace, string $zipPath, bool $overwrite = false): array
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException("Unable to open ZIP archive [{$zipPath}].");
        }

        $extracted = [];
        $skipped = [];
        $errors = [];
        $totalBytes = 0;
        $numFiles = $zip->numFiles;

        if ($numFiles > self::MAX_ENTRIES) {
            $zip->close();
            throw new \RuntimeException("Archive exceeds maximum entry count of ".self::MAX_ENTRIES.".");
        }

        for ($i = 0; $i < $numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (! $stat) {
                continue;
            }

            $entryName = $stat['name'];
            $size = $stat['size'];

            $totalBytes += $size;
            if ($totalBytes > self::MAX_TOTAL_BYTES) {
                $zip->close();
                throw new \RuntimeException("Archive exceeds maximum uncompressed size bound of 500 MB.");
            }

            // Skip directory entries ending in slash
            if (str_ends_with($entryName, '/')) {
                continue;
            }

            // Path Traversal & Zip-Slip Protection
            try {
                $absolutePath = $this->pathGuard->resolve(
                    $workspace,
                    $entryName,
                    mustExist: false,
                    mustBeMarkdown: false
                );
            } catch (PathTraversalRejected) {
                $errors[] = "Zip-slip / path traversal rejected for [{$entryName}]";
                $this->recorder->record(
                    AuditEvent::VAULT_PATH_TRAVERSAL_REJECTED,
                    $workspace->tenant_id,
                    $workspace->id,
                    null,
                    ['attempted_entry' => $entryName, 'reason' => 'zip_slip_rejected']
                );
                continue;
            }

            // Type allowlist
            $ext = strtolower(pathinfo($entryName, PATHINFO_EXTENSION));
            $allowedExtensions = ['md', 'markdown', 'txt', 'png', 'jpg', 'jpeg', 'svg', 'gif', 'webp', 'pdf', 'json'];

            if (! in_array($ext, $allowedExtensions, true)) {
                $skipped[] = "Disallowed file extension [{$ext}] for [{$entryName}]";
                continue;
            }

            if (is_file($absolutePath) && ! $overwrite) {
                $skipped[] = "Collision: file already exists [{$entryName}] (overwrite=false)";
                continue;
            }

            $parentDir = dirname($absolutePath);
            if (! is_dir($parentDir)) {
                @mkdir($parentDir, 0755, true);
            }

            $stream = $zip->getStream($entryName);
            if (! $stream) {
                $errors[] = "Failed to open stream for [{$entryName}]";
                continue;
            }

            $outStream = fopen($absolutePath, 'wb');
            if ($outStream) {
                stream_copy_to_stream($stream, $outStream);
                fclose($outStream);
                $extracted[] = $this->pathGuard->toRelative($workspace, $absolutePath);
            } else {
                $errors[] = "Failed to write target file for [{$entryName}]";
            }

            fclose($stream);
        }

        $zip->close();

        return [
            'extracted' => $extracted,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }
}
