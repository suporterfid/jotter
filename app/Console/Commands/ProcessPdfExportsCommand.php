<?php

namespace App\Console\Commands;

use App\Domain\Export\PdfExportRunner;
use Illuminate\Console\Command;

final class ProcessPdfExportsCommand extends Command
{
    protected $signature = 'pdf:process-exports {--limit= : Maximum number of queued exports to process}';

    protected $description = 'Process queued private PDF exports.';

    public function handle(PdfExportRunner $runner): int
    {
        $limit = (int) ($this->option('limit') ?: config('jotter.pdf.process_batch_size', 10));
        $processed = $runner->processQueued(max(1, $limit));

        $this->info("Processed {$processed} PDF export(s).");

        return self::SUCCESS;
    }
}
