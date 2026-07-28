<?php

namespace App\Http\Controllers;

use App\Domain\Vault\VaultExtractor;
use App\Domain\Vault\VaultReindexer;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WorkspaceImportController extends Controller
{
    public function __construct(
        private readonly VaultExtractor $extractor,
        private readonly VaultReindexer $reindexer,
    ) {}

    public function import(Request $request, Workspace $workspace): JsonResponse
    {
        $request->validate([
            'archive' => ['required', 'file', 'mimes:zip', 'max:51200'],
        ]);

        $file = $request->file('archive');
        if (! $file) {
            return response()->json(['message' => 'Archive file is required.'], 422);
        }

        $tempPath = storage_path('app/imports/import_'.uniqid('', true).'.zip');
        @mkdir(dirname($tempPath), 0755, true);

        $file->move(dirname($tempPath), basename($tempPath));

        try {
            $result = $this->extractor->extract($workspace, $tempPath);
            $this->reindexer->reindex($workspace);
        } finally {
            if (file_exists($tempPath)) {
                @unlink($tempPath);
            }
        }

        return response()->json([
            'status' => 'completed',
            'extracted_count' => count($result['extracted']),
            'skipped_count' => count($result['skipped']),
            'errors' => $result['errors'],
        ]);
    }
}
