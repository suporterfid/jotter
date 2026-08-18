<?php

namespace App\Domain\Export;

use App\Models\Note;
use App\Models\PdfExport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;

final class PdfExportRunner
{
    public function __construct(
        private readonly PdfDocumentRenderer $renderer,
    ) {}

    public function run(PdfExport $export): bool
    {
        $claimed = PdfExport::query()
            ->whereKey($export->id)
            ->where('status', 'queued')
            ->update([
                'status' => 'processing',
                'started_at' => now(),
                'failure_reason' => null,
            ]);

        if ($claimed !== 1) {
            return false;
        }

        $export->refresh();
        $outputPath = null;

        try {
            $noteIds = array_values(array_filter(array_map('intval', $export->note_ids ?? [])));
            $notes = Note::query()
                ->where('workspace_id', $export->workspace_id)
                ->whereIn('id', $noteIds)
                ->orderBy('path')
                ->get();

            $pdf = $this->renderer->renderWorkspace($export->workspace, $notes);
            $directory = (string) config('jotter.pdf.storage_path');
            File::ensureDirectoryExists($directory, 0750, true);
            $outputPath = rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$export->id.'-'.Str::uuid().'.pdf';

            if (file_put_contents($outputPath, $pdf, LOCK_EX) === false) {
                throw new \RuntimeException('Unable to persist PDF export.');
            }

            $export->forceFill([
                'status' => 'ready',
                'output_path' => $outputPath,
                'completed_at' => now(),
                'expires_at' => now()->addHours(max(1, (int) config('jotter.pdf.retention_hours', 24))),
            ])->save();

            return true;
        } catch (Throwable $exception) {
            if ($outputPath !== null && is_file($outputPath)) {
                @unlink($outputPath);
            }

            $export->forceFill([
                'status' => 'failed',
                'failure_reason' => Str::limit($exception->getMessage(), 1000),
                'completed_at' => now(),
            ])->save();

            report($exception);

            return false;
        }
    }

    public function processQueued(int $limit): int
    {
        $this->removeExpired();

        $processed = 0;
        PdfExport::query()
            ->where('status', 'queued')
            ->orderBy('queued_at')
            ->orderBy('created_at')
            ->limit(max(1, $limit))
            ->get()
            ->each(function (PdfExport $export) use (&$processed): void {
                $this->run($export);
                $processed++;
            });

        return $processed;
    }

    private function removeExpired(): void
    {
        $expired = PdfExport::query()
            ->where('status', 'ready')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->get();

        foreach ($expired as $export) {
            $path = $export->getRawOriginal('output_path');
            if (is_string($path) && is_file($path)) {
                @unlink($path);
            }

            $export->forceFill([
                'status' => 'expired',
                'output_path' => null,
            ])->save();
        }
    }
}
