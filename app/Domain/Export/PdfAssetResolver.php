<?php

namespace App\Domain\Export;

use App\Domain\Vault\VaultPathGuard;
use App\Models\Note;
use App\Models\Workspace;

final class PdfAssetResolver
{
    public function __construct(
        private readonly VaultPathGuard $paths = new VaultPathGuard,
    ) {}

    public function inlineLocalImages(string $html, Workspace $workspace, Note $note): string
    {
        $document = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<div id="pdf-asset-root">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        foreach (iterator_to_array($document->getElementsByTagName('img')) as $image) {
            $source = trim((string) $image->getAttribute('src'));
            $dataUri = $this->dataUriFor($source, $workspace, $note);

            if ($dataUri === null) {
                $image->removeAttribute('src');
                continue;
            }

            $image->setAttribute('src', $dataUri);
        }

        $root = $document->getElementById('pdf-asset-root');

        return $root ? $this->innerHtml($root) : '';
    }

    private function dataUriFor(string $source, Workspace $workspace, Note $note): ?string
    {
        if (preg_match('/^data:image\/[a-z0-9.+-]+;base64,[a-z0-9+\/=\r\n]+$/i', $source) === 1) {
            return strlen($source) <= 5_000_000 ? $source : null;
        }

        if ($source === '' || str_starts_with($source, '//') || preg_match('/^[a-z][a-z0-9+.-]*:/i', $source) === 1) {
            return null;
        }

        $relativePath = $this->resolveRelativePath($note->path, $source);
        if ($relativePath === null) {
            return null;
        }

        try {
            $absolutePath = $this->paths->resolve($workspace, $relativePath, mustExist: true, mustBeMarkdown: false);
        } catch (\Throwable) {
            return null;
        }

        if (! is_file($absolutePath)) {
            return null;
        }

        $mime = strtolower((string) (mime_content_type($absolutePath) ?: ''));
        if (! str_starts_with($mime, 'image/')) {
            return null;
        }

        $contents = file_get_contents($absolutePath);
        if ($contents === false || strlen($contents) > 5_000_000) {
            return null;
        }

        return 'data:'.$mime.';base64,'.base64_encode($contents);
    }

    private function resolveRelativePath(string $notePath, string $source): ?string
    {
        $baseDirectory = dirname(str_replace('\\', '/', $notePath));
        $baseDirectory = $baseDirectory === '.' ? '' : trim($baseDirectory, '/');
        $parts = $baseDirectory === '' ? [] : explode('/', $baseDirectory);

        foreach (explode('/', str_replace('\\', '/', $source)) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }

            if ($part === '..') {
                if ($parts === []) {
                    return null;
                }
                array_pop($parts);
                continue;
            }

            if (str_contains($part, "\0") || str_contains($part, ':')) {
                return null;
            }

            $parts[] = $part;
        }

        return $parts === [] ? null : implode('/', $parts);
    }

    private function innerHtml(\DOMElement $element): string
    {
        $html = '';
        foreach ($element->childNodes as $child) {
            $html .= $element->ownerDocument?->saveHTML($child) ?? '';
        }

        return $html;
    }
}
