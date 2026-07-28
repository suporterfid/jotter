<?php

namespace App\Domain\Vault;

final class BlockRegistry
{
    /**
     * Declarative specification of supported blocks, HTML elements, allowed attributes, and slash-menu metadata.
     *
     * @return array<string, array{name: string, syntax: string, allowed_tags: list<string>, allowed_attributes: list<string>, slash_menu: array{label: string, icon: string}}>
     */
    public static function definitions(): array
    {
        return [
            'task_list' => [
                'name' => 'Task List',
                'syntax' => '- [ ] Task item',
                'allowed_tags' => ['ul', 'li', 'input', 'label'],
                'allowed_attributes' => ['type', 'checked', 'disabled', 'class'],
                'slash_menu' => ['label' => 'To-do List', 'icon' => 'check-square'],
            ],
            'code_block' => [
                'name' => 'Code Block',
                'syntax' => "```js\ncode\n```",
                'allowed_tags' => ['pre', 'code', 'button', 'span'],
                'allowed_attributes' => ['class', 'data-language', 'data-copy'],
                'slash_menu' => ['label' => 'Code Block', 'icon' => 'code'],
            ],
            'wikilink' => [
                'name' => 'Wikilink',
                'syntax' => '[[Note Title]]',
                'allowed_tags' => ['a'],
                'allowed_attributes' => ['href', 'class', 'data-target', 'title'],
                'slash_menu' => ['label' => 'Link Note', 'icon' => 'link'],
            ],
            'callout' => [
                'name' => 'Callout',
                'syntax' => '> [!NOTE] Callout content',
                'allowed_tags' => ['div', 'p', 'span'],
                'allowed_attributes' => ['class', 'data-callout-type'],
                'slash_menu' => ['label' => 'Callout Box', 'icon' => 'info'],
            ],
            'toggle' => [
                'name' => 'Toggle List',
                'syntax' => '<details><summary>Toggle</summary>Content</details>',
                'allowed_tags' => ['details', 'summary', 'p', 'div'],
                'allowed_attributes' => ['open', 'class'],
                'slash_menu' => ['label' => 'Toggle', 'icon' => 'chevron-right'],
            ],
            'table' => [
                'name' => 'Table',
                'syntax' => "| Header 1 | Header 2 |\n| --- | --- |\n| Cell 1 | Cell 2 |",
                'allowed_tags' => ['table', 'thead', 'tbody', 'tr', 'th', 'td'],
                'allowed_attributes' => ['class', 'align'],
                'slash_menu' => ['label' => 'Table', 'icon' => 'grid'],
            ],
            'divider' => [
                'name' => 'Horizontal Divider',
                'syntax' => '---',
                'allowed_tags' => ['hr'],
                'allowed_attributes' => ['class'],
                'slash_menu' => ['label' => 'Divider', 'icon' => 'minus'],
            ],
        ];
    }

    /**
     * Derive server-side allowed HTML tags array.
     *
     * @return list<string>
     */
    public static function allowedTags(): array
    {
        $tags = ['p', 'br', 'em', 'strong', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'ol', 'ul', 'li'];
        foreach (self::definitions() as $def) {
            foreach ($def['allowed_tags'] as $tag) {
                if (! in_array($tag, $tags, true)) {
                    $tags[] = $tag;
                }
            }
        }

        return array_values($tags);
    }

    /**
     * Derive server-side allowed attributes array.
     *
     * @return list<string>
     */
    public static function allowedAttributes(): array
    {
        $attrs = ['class', 'id'];
        foreach (self::definitions() as $def) {
            foreach ($def['allowed_attributes'] as $attr) {
                if (! in_array($attr, $attrs, true)) {
                    $attrs[] = $attr;
                }
            }
        }

        return array_values($attrs);
    }
}
