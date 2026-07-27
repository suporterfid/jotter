<?php

namespace App\Http\Controllers;

use App\Domain\Vault\AttachmentStorage;
use App\Domain\Vault\Exceptions\PathTraversalRejected;
use App\Domain\Vault\Exceptions\VaultNoteNotFound;
use App\Models\Attachment;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AttachmentController extends Controller
{
    public function __construct(
        private readonly AttachmentStorage $storage,
    ) {}

    public function index(Workspace $workspace): JsonResponse
    {
        $attachments = Attachment::query()
            ->where('workspace_id', $workspace->id)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function (Attachment $attachment) use ($workspace) {
                return array_merge($attachment->toArray(), [
                    'url' => url("/api/workspaces/{$workspace->id}/attachments/{$attachment->path}"),
                ]);
            });

        return response()->json(['data' => $attachments]);
    }

    public function store(Request $request, Workspace $workspace): JsonResponse
    {
        $maxKb = (int) config('jotter.attachments.max_size_kb', 20480);

        $request->validate([
            'file' => ['required', 'file', "max:{$maxKb}"],
            'path' => ['nullable', 'string', 'max:700'],
        ]);

        $file = $request->file('file');
        if (! $file || ! $file->isValid()) {
            return response()->json(['message' => 'Invalid file upload.'], 400);
        }

        $extension = strtolower($file->getClientOriginalExtension());
        $mime = strtolower($file->getClientMimeType() ?: $file->getMimeType() ?: '');

        $allowedExts = config('jotter.attachments.allowed_extensions', []);
        $allowedMimes = config('jotter.attachments.allowed_mimes', []);

        if ($extension !== '' && ! in_array($extension, $allowedExts, true)) {
            return response()->json([
                'message' => "File extension [.{$extension}] is not allowed.",
                'errors' => ['file' => ["File extension [.{$extension}] is not allowed."]],
            ], 422);
        }

        if ($mime !== '' && ! in_array($mime, $allowedMimes, true)) {
            return response()->json([
                'message' => "MIME type [{$mime}] is not allowed.",
                'errors' => ['file' => ["MIME type [{$mime}] is not allowed."]],
            ], 422);
        }

        $path = $request->input('path');
        $attachment = $this->storage->writeAttachment($workspace, $file, $path);

        $data = array_merge($attachment->toArray(), [
            'url' => url("/api/workspaces/{$workspace->id}/attachments/{$attachment->path}"),
        ]);

        return response()->json(['data' => $data], 201);
    }

    public function show(Request $request, Workspace $workspace, string $path): BinaryFileResponse|JsonResponse
    {
        try {
            [$absolutePath, $mime, $size] = $this->storage->readAttachmentInfo($workspace, $path);

            return response()->file($absolutePath, [
                'Content-Type' => $mime,
                'Content-Length' => (string) $size,
                'Cache-Control' => 'private, max-age=86400',
                'Content-Disposition' => 'inline',
            ]);
        } catch (VaultNoteNotFound | PathTraversalRejected) {
            return response()->json(['message' => "Attachment [{$path}] not found."], 404);
        }
    }

    public function destroy(Workspace $workspace, string $attachment): JsonResponse
    {
        $existing = $this->storage->findAttachment($workspace, $attachment);
        if (! $existing && ! is_numeric($attachment)) {
            try {
                $this->storage->readAttachmentInfo($workspace, $attachment);
            } catch (VaultNoteNotFound | PathTraversalRejected) {
                return response()->json(['message' => "Attachment [{$attachment}] not found."], 404);
            }
        } elseif (! $existing) {
            return response()->json(['message' => "Attachment [{$attachment}] not found."], 404);
        }

        $this->storage->deleteAttachment($workspace, $attachment);

        return response()->json(['message' => 'Attachment deleted successfully.']);
    }
}
