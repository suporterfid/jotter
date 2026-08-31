<?php

namespace App\Http\Controllers;

use App\Domain\Auth\Contracts\IdentityProvider;
use App\Domain\Vault\ImportSource;
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
        private readonly IdentityProvider $identityProvider,
    ) {}

    public function import(Request $request, Workspace $workspace): JsonResponse
    {
        $subject = $this->identityProvider->resolveIdentity($request);
        $request->validate([
            'archive' => ['required', 'file', 'mimes:zip', 'max:51200'],
            'source' => ['nullable', 'string', 'in:'.implode(',', ImportSource::values())],
        ]);
        $source = ImportSource::fromInput($request->input('source'));

        $file = $request->file('archive');
        if (! $file) {
            return response()->json(['message' => __('messages.archive_file_required')], 422);
        }

        $tempPath = storage_path('app/imports/import_'.uniqid('', true).'.zip');
        @mkdir(dirname($tempPath), 0755, true);

        $file->move(dirname($tempPath), basename($tempPath));

        try {
            $overwrite = $request->boolean('overwrite', false);
            $result = $this->extractor->extract($workspace, $tempPath, $overwrite, $source);
            $this->reindexer->reindex($workspace, null, $subject?->subjectId);
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
