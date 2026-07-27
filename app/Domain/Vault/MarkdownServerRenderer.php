<?php

namespace App\Domain\Vault;

use League\CommonMark\CommonMarkConverter;

final class MarkdownServerRenderer
{
    private readonly CommonMarkConverter $converter;

    public function __construct()
    {
        $this->converter = new CommonMarkConverter([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]);
    }

    public function render(string $markdown): string
    {
        if (trim($markdown) === '') {
            return '';
        }

        // Convert Wikilinks [[target|alias]] into safe anchor tags
        $processed = preg_replace_callback(
            '/\[\[([^\]|#]+)(?:#[^\]|]+)?(?:\|([^\]]+))?\]\]/',
            function (array $matches): string {
                $target = htmlspecialchars(trim($matches[1]), ENT_QUOTES, 'UTF-8');
                $label = htmlspecialchars(trim($matches[2] ?? $matches[1]), ENT_QUOTES, 'UTF-8');

                return sprintf('<a class="wikilink" data-target="%s" href="#/note/%s">%s</a>', $target, urlencode($target), $label);
            },
            $markdown
        );

        $html = (string) $this->converter->convert($processed ?? $markdown);

        // Server-side XSS sanitization pass
        return $this->sanitizeHtml($html);
    }

    private function sanitizeHtml(string $html): string
    {
        // Strip script tags, event handlers, and javascript: URIs
        $clean = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $html);
        $clean = preg_replace('/on[a-z]+\s*=\s*"[^"]*"/i', '', $clean ?? '');
        $clean = preg_replace('/on[a-z]+\s*=\s*\'[^\']*\'/i', '', $clean ?? '');
        $clean = preg_replace('/href\s*=\s*"javascript:[^"]*"/i', 'href="#"', $clean ?? '');

        return $clean ?? '';
    }
}
