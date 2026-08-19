<?php

namespace App\Http\Controllers;

use App\Domain\Sharing\NoteShareService;
use App\Domain\Sharing\SharedAssetResolver;
use App\Domain\Sharing\SharedNoteRenderer;
use App\Domain\Vault\AttachmentStorage;
use App\Domain\Vault\Exceptions\PathTraversalRejected;
use App\Domain\Vault\Exceptions\VaultNoteNotFound;
use App\Models\NoteShare;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class PublicNoteShareController extends Controller
{
    public function __construct(
        private readonly NoteShareService $shares,
        private readonly SharedNoteRenderer $renderer,
        private readonly SharedAssetResolver $assets,
        private readonly AttachmentStorage $attachments,
    ) {}

    public function show(Request $request, string $token): mixed
    {
        $share = $this->shares->activeForToken($token);
        if ($share === null) {
            abort(404);
        }

        try {
            $payload = $this->renderer->render($share, $token);
        } catch (VaultNoteNotFound | PathTraversalRejected) {
            abort(404);
        }

        $share->forceFill(['last_accessed_at' => now()])->saveQuietly();
        $themeLabels = [
            'preference' => __('messages.theme_preference'),
            'system' => __('messages.theme_system'),
            'light' => __('messages.theme_light'),
            'dark' => __('messages.theme_dark'),
        ];

        return view('publish.page', [
            ...$payload,
            'assetPrefix' => '/share-assets/',
            'themeLabels' => $themeLabels,
        ]);
    }

    public function attachment(string $token, string $path): BinaryFileResponse
    {
        $share = $this->shares->activeForToken($token);
        if ($share === null) {
            abort(404);
        }

        try {
            $share->loadMissing('note.workspace');
            $attachment = $this->assets->findRegisteredAttachment($share->note->workspace, $path);
            if ($attachment === null) {
                abort(404);
            }
            [$absolutePath, $mime, $size] = $this->attachments->readAttachmentInfo(
                $share->note->workspace,
                $attachment->path,
            );
        } catch (PathTraversalRejected | VaultNoteNotFound) {
            abort(404);
        }

        return response()->file($absolutePath, [
            'Content-Type' => $mime,
            'Content-Length' => (string) $size,
            'Cache-Control' => 'no-store',
            'Content-Disposition' => 'inline',
        ]);
    }
}
