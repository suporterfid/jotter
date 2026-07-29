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

        // Strip Obsidian comments %% ... %% before any further processing;
        // they must never leak into rendered SPA or published output.
        $processed = preg_replace('/%%.*?%%/s', '', $markdown);

        // Optional LaTeX/Mermaid hydration markup (off by default). Placeholders
        // survive the CommonMark pass untouched (plain alnum tokens), then get
        // swapped for the real, escaped markup after conversion so CommonMark
        // never has a chance to mangle or re-escape it.
        $mathMermaidBlocks = [];
        if ((bool) config('jotter.rendering.katex_mermaid_enabled', false)) {
            $processed = $this->extractMathAndMermaid($processed ?? $markdown, $mathMermaidBlocks);
        }

        // Convert Wikilinks [[target|alias]] into safe anchor tags
        $processed = preg_replace_callback(
            '/\[\[([^\]|#]+)(?:#[^\]|]+)?(?:\|([^\]]+))?\]\]/',
            function (array $matches): string {
                $target = htmlspecialchars(trim($matches[1]), ENT_QUOTES, 'UTF-8');
                $label = htmlspecialchars(trim($matches[2] ?? $matches[1]), ENT_QUOTES, 'UTF-8');

                return sprintf('<a class="wikilink" data-target="%s" href="#/note/%s">%s</a>', $target, urlencode($target), $label);
            },
            $processed
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

        if ($mathMermaidBlocks !== []) {
            $html = strtr($html, $mathMermaidBlocks);
        }

        // Server-side XSS sanitization pass
        return $this->sanitizeHtml($html);
    }

    /**
     * Replaces $$LaTeX$$ and ```mermaid fenced blocks with plain-text
     * placeholder tokens (so CommonMark cannot mangle them) and records the
     * real, escaped hydration markup to swap back in after conversion.
     *
     * @param  array<string, string>  $blocks
     */
    private function extractMathAndMermaid(string $markdown, array &$blocks): string
    {
        $index = 0;

        $markdown = preg_replace_callback(
            '/```mermaid\s*\n(.*?)```/s',
            function (array $matches) use (&$blocks, &$index): string {
                $token = sprintf('JOTTERMERMAIDBLOCK%dTOKEN', $index++);
                $source = htmlspecialchars(trim($matches[1]), ENT_QUOTES, 'UTF-8');
                $blocks[$token] = sprintf('<pre class="mermaid">%s</pre>', $source);

                return $token;
            },
            $markdown
        ) ?? $markdown;

        $markdown = preg_replace_callback(
            '/\$\$(.+?)\$\$/s',
            function (array $matches) use (&$blocks, &$index): string {
                $token = sprintf('JOTTERMATHBLOCK%dTOKEN', $index++);
                $source = htmlspecialchars(trim($matches[1]), ENT_QUOTES, 'UTF-8');
                $blocks[$token] = sprintf('<span class="jotter-math" data-tex="%s">%s</span>', $source, $source);

                return $token;
            },
            $markdown
        ) ?? $markdown;

        return $markdown;
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
