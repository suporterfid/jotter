<?php

namespace App\Http\Controllers;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class PublicShareAssetController extends Controller
{
    public function css(): BinaryFileResponse
    {
        return $this->file(resource_path('views/publish/publish.css'));
    }

    public function theme(): BinaryFileResponse
    {
        return $this->file(resource_path('views/publish/publish-theme.js'));
    }

    public function font(string $font): BinaryFileResponse
    {
        $allowed = [
            'inter-400.woff2',
            'inter-600.woff2',
            'inter-700.woff2',
            'source-serif-4-700.woff2',
            'ibm-plex-mono-400.woff2',
        ];
        if (! in_array($font, $allowed, true)) {
            abort(404);
        }

        return $this->file(base_path('frontend/src/assets/fonts/'.$font));
    }

    private function file(string $path): BinaryFileResponse
    {
        if (! is_file($path)) {
            abort(404);
        }

        return response()->file($path);
    }
}
