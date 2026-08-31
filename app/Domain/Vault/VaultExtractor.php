<?php

namespace App\Domain\Vault;

use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditRecorder;
use App\Models\Workspace;
use InvalidArgumentException;
use RuntimeException;
use ZipArchive;

final class VaultExtractor
{
    private const MAX_ENTRY_COUNT = 5000;

    private const MAX_TOTAL_UNCOMPRESSED_SIZE = 104_857_600; // 100 MB

    private const ALLOWED_EXTENSIONS = [
        'md', 'markdown', 'txt',
        'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp',
        'pdf', 'json',
    ];

    private const DISALLOWED_EXTENSIONS = [
        'php', 'phtml', 'php3', 'php4', 'php5', 'phar', 'inc',
        'sh', 'bash', 'exe', 'bat', 'cmd', 'js', 'py', 'pl', 'rb',
    ];

    public function __construct(
        private readonly VaultPathGuard $pathGuard,
        private readonly AuditRecorder $auditRecorder,
        private readonly ImportPathNormalizer $normalizer = new ImportPathNormalizer,
    ) {}

    /**
     * Extracts and validates an archive (ZIP or JSON backup) into the workspace vault.
     *
     * @return array{extracted: list<string>, skipped: list<string>, errors: list<string>}
     */
    public function extract(Workspace $workspace, string $archivePath, bool $overwrite = false, ImportSource $source = ImportSource::GENERIC): array
    {
        if (! file_exists($archivePath)) {
            throw new InvalidArgumentException("Archive file does not exist: {$archivePath}");
        }

        $ext = strtolower(pathinfo($archivePath, PATHINFO_EXTENSION));
        if ($ext === 'json') {
            return $this->processJsonBackup($workspace, $archivePath, $overwrite);
        }

        $zip = new ZipArchive;
        $openResult = $zip->open($archivePath);
        if ($openResult !== true) {
            throw new RuntimeException("Failed to open zip archive, error code: {$openResult}");
        }

        try {
            return $this->processZip($workspace, $zip, $overwrite, $source);
        } finally {
            $zip->close();
        }
    }

    /**
     * @return array{extracted: list<string>, skipped: list<string>, errors: list<string>}
     */
    private function processJsonBackup(Workspace $workspace, string $jsonPath, bool $overwrite): array
    {
        $raw = file_get_contents($jsonPath);
        $data = json_decode($raw, true);

        if (! is_array($data) || ! isset($data['version'])) {
            throw new InvalidArgumentException('Invalid JSON backup schema: missing version header');
        }

        if ($data['version'] !== '1.0') {
            throw new InvalidArgumentException("Unsupported JSON backup format version: {$data['version']}");
        }

        $notes = $data['notes'] ?? [];
        $extracted = [];
        $skipped = [];
        $errors = [];

        foreach ($notes as $note) {
            $name = $note['path'] ?? null;
            $content = $note['content'] ?? '';

            if (! $name) {
                continue;
            }

            try {
                $resolvedPath = $this->pathGuard->resolve($workspace, $name, mustExist: false, mustBeMarkdown: false);
            } catch (\Throwable $e) {
                $errors[] = "Zip-slip path traversal rejected: {$name}";
                $skipped[] = $name;

                continue;
            }

            if (! $overwrite && file_exists($resolvedPath)) {
                $skipped[] = $name;

                continue;
            }

            $destDir = dirname($resolvedPath);
            if (! is_dir($destDir)) {
                mkdir($destDir, 0755, true);
            }

            file_put_contents($resolvedPath, $content);
            $extracted[] = $name;
        }

        return [
            'extracted' => $extracted,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    /**
     * @return array{extracted: list<string>, skipped: list<string>, errors: list<string>}
     */
    private function processZip(Workspace $workspace, ZipArchive $zip, bool $overwrite, ImportSource $source = ImportSource::GENERIC): array
    {
        $numFiles = $zip->numFiles;

        // Obsidian and Notion exports usually wrap everything in one folder.
        $rootPrefix = null;
        if ($source !== ImportSource::GENERIC) {
            $entryNames = [];
            for ($i = 0; $i < $numFiles; $i++) {
                $entryName = (string) $zip->getNameIndex($i);
                if ($entryName !== '' && ! str_ends_with($entryName, '/')) {
                    $entryNames[] = $entryName;
                }
            }
            $rootPrefix = $this->normalizer->detectRootDirectory($entryNames);
        }
        if ($numFiles > self::MAX_ENTRY_COUNT) {
            $this->auditRecorder->record(
                AuditEvent::VAULT_PATH_TRAVERSAL_REJECTED,
                $workspace->tenant_id,
                $workspace->id,
                null,
                ['reason' => 'max_entry_count_exceeded', 'count' => $numFiles]
            );
            throw new RuntimeException('Archive exceeds maximum allowed entry count of '.self::MAX_ENTRY_COUNT);
        }

        $totalSize = 0;
        $validEntries = [];
        $errors = [];
        $skipped = [];
        $extracted = [];

        for ($i = 0; $i < $numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (! $stat) {
                continue;
            }

            $entryName = $stat['name'];

            if (str_ends_with($entryName, '/')) {
                continue;
            }

            $name = $this->normalizer->normalize($source, $entryName, $rootPrefix);
            if ($name === null) {
                $skipped[] = $entryName;

                continue;
            }

            $externalAttr = 0;
            $opsys = 0;
            $zip->getExternalAttributesIndex($i, $opsys, $externalAttr);
            if ($opsys === 3 && (($externalAttr >> 16) & 0170000) === 0120000) {
                $this->auditRecorder->record(
                    AuditEvent::VAULT_PATH_TRAVERSAL_REJECTED,
                    $workspace->tenant_id,
                    $workspace->id,
                    null,
                    ['reason' => 'symlink_entry_prohibited', 'target' => $name]
                );
                $errors[] = "Symlink entry prohibited: {$name}";
                $skipped[] = $name;

                continue;
            }

            if (str_contains($name, "\0") || str_contains($name, '\\') || preg_match('/\.[ \.]+$|^\.\.|\/\.\.\//', $name)) {
                $this->auditRecorder->record(
                    AuditEvent::VAULT_PATH_TRAVERSAL_REJECTED,
                    $workspace->tenant_id,
                    $workspace->id,
                    null,
                    ['reason' => 'invalid_path_encoding', 'target' => $name]
                );
                $errors[] = "Invalid or aliased path rejected: {$name}";
                $skipped[] = $name;

                continue;
            }

            try {
                $resolvedPath = $this->pathGuard->resolve($workspace, $name, mustExist: false, mustBeMarkdown: false);
            } catch (\Throwable $e) {
                $this->auditRecorder->record(
                    AuditEvent::VAULT_PATH_TRAVERSAL_REJECTED,
                    $workspace->tenant_id,
                    $workspace->id,
                    null,
                    ['reason' => 'path_traversal_zip_slip', 'target' => $name]
                );
                $errors[] = "Zip-slip path traversal rejected: {$name}";
                $skipped[] = $name;

                continue;
            }

            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (in_array($ext, self::DISALLOWED_EXTENSIONS, true) || ! in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
                $this->auditRecorder->record(
                    AuditEvent::VAULT_PATH_TRAVERSAL_REJECTED,
                    $workspace->tenant_id,
                    $workspace->id,
                    null,
                    ['reason' => 'disallowed_extension', 'extension' => $ext, 'target' => $name]
                );
                $errors[] = "Disallowed file type: {$name}";
                $skipped[] = $name;

                continue;
            }

            if (! $overwrite && file_exists($resolvedPath)) {
                $skipped[] = $name;

                continue;
            }

            $uncompressedSize = $stat['size'];
            $totalSize += $uncompressedSize;

            if ($totalSize > self::MAX_TOTAL_UNCOMPRESSED_SIZE) {
                $this->auditRecorder->record(
                    AuditEvent::VAULT_PATH_TRAVERSAL_REJECTED,
                    $workspace->tenant_id,
                    $workspace->id,
                    null,
                    ['reason' => 'max_total_size_exceeded', 'total_size' => $totalSize]
                );
                throw new RuntimeException('Archive total size exceeds maximum limit of '.self::MAX_TOTAL_UNCOMPRESSED_SIZE.' bytes');
            }

            $validEntries[] = [
                'index' => $i,
                'entry' => $entryName,
                'name' => $name,
                'destination' => $resolvedPath,
                'rewrite' => $source === ImportSource::NOTION && in_array($ext, ['md', 'markdown'], true),
            ];
        }

        foreach ($validEntries as $entry) {
            $stream = $zip->getStream($entry['entry']);
            if (! $stream) {
                $errors[] = "Failed to stream entry: {$entry['name']}";
                $skipped[] = $entry['name'];

                continue;
            }

            $destDir = dirname($entry['destination']);
            if (! is_dir($destDir)) {
                mkdir($destDir, 0755, true);
            }

            if ($entry['rewrite']) {
                $markdown = (string) stream_get_contents($stream);
                fclose($stream);
                file_put_contents($entry['destination'], $this->normalizer->rewriteNotionMarkdown($markdown));
                $extracted[] = $entry['name'];

                continue;
            }

            $outStream = fopen($entry['destination'], 'wb');
            if (! $outStream) {
                fclose($stream);
                $errors[] = "Failed to open output destination: {$entry['destination']}";
                $skipped[] = $entry['name'];

                continue;
            }

            stream_copy_to_stream($stream, $outStream);
            fclose($stream);
            fclose($outStream);

            $extracted[] = $entry['name'];
        }

        return [
            'extracted' => $extracted,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }
}
