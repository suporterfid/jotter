<?php

namespace App\Domain\Vault;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\MarkdownConverter;

final class MarkdownServerRenderer
{
    private readonly MarkdownConverter $converter;

    public function __construct()
    {
        $environment = new Environment([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new TableExtension());

        $this->converter = new MarkdownConverter($environment);
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

        // Convert Callouts > [!NOTE] content into styled div block
        $processed = preg_replace_callback(
            '/>\s*\[!([A-Z]+)\]\s*(.*?)(?=\n\n|\n$|$)/s',
            function (array $matches): string {
                $type = strtolower(trim($matches[1]));
                $content = htmlspecialchars(trim($matches[2]), ENT_QUOTES, 'UTF-8');
                return sprintf('<div class="callout" data-callout-type="%s"><p>%s</p></div>', $type, $content);
            },
            $processed ?? $markdown
        );

        $html = (string) $this->converter->convert($processed ?? $markdown);

        // Unescape safe html details and summary tags
        $html = str_replace(
            ['&lt;details&gt;', '&lt;/details&gt;', '&lt;summary&gt;', '&lt;/summary&gt;'],
            ['<details>', '</details>', '<summary>', '</summary>'],
            $html
        );

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
