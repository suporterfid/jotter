<?php

namespace App\Domain\Sharing;

use App\Domain\Vault\VaultPathGuard;
use App\Models\Attachment;
use App\Models\Note;
use App\Models\NoteShare;
use App\Models\Workspace;
use DOMDocument;
use DOMElement;

final class SharedAssetResolver
{
    public function __construct(
        private readonly VaultPathGuard $paths,
    ) {}

    public function rewriteAttachmentUrls(string $html, Note $note, string $plainToken): string
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML(
            '<?xml encoding="UTF-8">'.$html,
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            return $html;
        }

        foreach ($document->getElementsByTagName('*') as $element) {
            if (! $element instanceof DOMElement) {
                continue;
            }

            $attribute = match (strtolower($element->tagName)) {
                'img' => 'src',
                'a' => 'href',
                default => null,
            };
            if ($attribute === null || ! $element->hasAttribute($attribute)) {
                continue;
            }

            $attachment = $this->resolveMarkdownAttachment($note, $element->getAttribute($attribute));
            if ($attachment === null) {
                continue;
            }

            $element->setAttribute(
                $attribute,
                route('public.note-share.attachment', [
                    'token' => $plainToken,
                    'path' => $attachment->path,
                ]),
            );
        }

        $serialized = $document->saveHTML() ?: $html;

        return preg_replace('/^<\?xml[^>]*>\s*/', '', $serialized) ?: $serialized;
    }

    public function findRegisteredAttachment(Workspace $workspace, string $path): ?Attachment
    {
        $decodedPath = rawurldecode($path);
        if ($decodedPath === '' || str_contains($decodedPath, "\0")) {
            return null;
        }

        $attachment = Attachment::query()
            ->where('workspace_id', $workspace->id)
            ->where('path', $decodedPath)
            ->first();
        if ($attachment === null) {
            return null;
        }

        $this->paths->resolve($workspace, $attachment->path, mustExist: true, mustBeMarkdown: false);

        return $attachment;
    }

    private function resolveMarkdownAttachment(Note $note, string $url): ?Attachment
    {
        if ($this->isExternalOrNonFileUrl($url)) {
            return null;
        }

        $decoded = rawurldecode($url);
        $path = parse_url($decoded, PHP_URL_PATH);
        if (! is_string($path) || $path === '') {
            return null;
        }

        $relativePath = $this->normalizeRelativePath(dirname($note->path), $path);
        if ($relativePath === null) {
            return null;
        }

        $attachment = Attachment::query()
            ->where('workspace_id', $note->workspace_id)
            ->where('path', $relativePath)
            ->first();
        if ($attachment === null) {
            return null;
        }

        $this->paths->resolve($note->workspace, $attachment->path, mustExist: true, mustBeMarkdown: false);

        return $attachment;
    }

    private function isExternalOrNonFileUrl(string $url): bool
    {
        return $url === ''
            || str_starts_with($url, '#')
            || str_starts_with($url, '/')
            || str_starts_with($url, '//')
            || preg_match('/^[a-z][a-z0-9+.-]*:/i', $url) === 1;
    }

    private function normalizeRelativePath(string $directory, string $path): ?string
    {
        $combined = ($directory === '.' ? '' : trim($directory, '/').'/').$path;
        $segments = [];

        foreach (explode('/', str_replace('\\', '/', $combined)) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($segments === []) {
                    return null;
                }
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        return $segments === [] ? null : implode('/', $segments);
    }
}
