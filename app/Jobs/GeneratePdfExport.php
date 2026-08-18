<?php

namespace App\Jobs;

use App\Domain\Export\PdfExportRunner;
use App\Models\PdfExport;

final class GeneratePdfExport
{
    public function __construct(
        public readonly string $exportId,
    ) {}

    public function handle(PdfExportRunner $runner): void
    {
        $export = PdfExport::query()->find($this->exportId);
        if ($export !== null) {
            $runner->run($export);
        }
    }
}
