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
            return response()->json(['message' => __('messages.invalid_file_upload')], 400);
        }

        $extension = strtolower($file->getClientOriginalExtension());
        $mime = strtolower($file->getClientMimeType() ?: $file->getMimeType() ?: '');

        $allowedExts = config('jotter.attachments.allowed_extensions', []);
        $allowedMimes = config('jotter.attachments.allowed_mimes', []);

        if ($extension !== '' && ! in_array($extension, $allowedExts, true)) {
            $message = __('messages.file_extension_not_allowed', ['extension' => ".{$extension}"]);

            return response()->json([
                'message' => $message,
                'errors' => ['file' => [$message]],
            ], 422);
        }

        if ($mime !== '' && ! in_array($mime, $allowedMimes, true)) {
            $message = __('messages.mime_type_not_allowed', ['mime' => $mime]);

            return response()->json([
                'message' => $message,
                'errors' => ['file' => [$message]],
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
            return response()->json(['message' => __('messages.attachment_not_found', ['path' => $path])], 404);
        }
    }

    public function destroy(Workspace $workspace, string $attachment): JsonResponse
    {
        $existing = $this->storage->findAttachment($workspace, $attachment);
        if (! $existing && ! is_numeric($attachment)) {
            try {
                $this->storage->readAttachmentInfo($workspace, $attachment);
            } catch (VaultNoteNotFound | PathTraversalRejected) {
                return response()->json(['message' => __('messages.attachment_not_found', ['path' => $attachment])], 404);
            }
        } elseif (! $existing) {
            return response()->json(['message' => __('messages.attachment_not_found', ['path' => $attachment])], 404);
        }

        $this->storage->deleteAttachment($workspace, $attachment);

        return response()->json(['message' => __('messages.attachment_deleted_successfully')]);
    }
}
