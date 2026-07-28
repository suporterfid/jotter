<?php

namespace App\Http\Controllers;

use App\Domain\Auth\Contracts\IdentityProvider;
use App\Models\Note;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

final class WorkspaceExportController extends Controller
{
    public function __construct(
        private readonly IdentityProvider $identityProvider
    ) {}

    public function export(Request $request, int $workspaceId): JsonResponse|BinaryFileResponse
    {
        $subject = $this->identityProvider->resolveIdentity($request);
        if (! $subject) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (! $this->identityProvider->isAuthorizedForWorkspace($subject, $workspaceId)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $workspace = Workspace::query()->find($workspaceId);
        if (! $workspace) {
            return response()->json(['message' => 'Workspace not found.'], 404);
        }

        $exportDir = storage_path('app/private');
        if (! is_dir($exportDir)) {
            mkdir($exportDir, 0755, true);
        }

        $zipPath = "{$exportDir}/export_workspace_{$workspace->id}.zip";
        @unlink($zipPath);

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return response()->json(['message' => 'Failed to create export zip archive.'], 500);
        }

        $notes = Note::query()->where('workspace_id', $workspaceId)->get();
        $hasFiles = false;

        $pathGuard = new \App\Domain\Vault\VaultPathGuard();
        foreach ($notes as $note) {
            try {
                $fullPath = $pathGuard->resolve($workspace, $note->path, mustExist: true, mustBeMarkdown: false);
                $zip->addFile($fullPath, $note->path);
                $hasFiles = true;
            } catch (\Throwable) {
                // file missing
            }
        }

        if (! $hasFiles) {
            $zip->addFromString('README.md', "# Workspace Export: {$workspace->name}\n\nExport generated on ".now()->toIso8601String());
        }

        $zip->close();

        return response()->download($zipPath, "workspace_{$workspace->slug}_export.zip");
    }
}
