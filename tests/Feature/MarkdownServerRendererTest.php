<?php

namespace Tests\Feature;

use App\Domain\Vault\MarkdownServerRenderer;
use Tests\TestCase;

final class MarkdownServerRendererTest extends TestCase
{
    public function test_markdown_server_renderer_escapes_unsafe_html_and_script_tags(): void
    {
        $renderer = new MarkdownServerRenderer();

        $dirty = "# Welcome\n\n<script>alert('xss')</script>\n\nClick [here](javascript:alert(1)) or [[Target Note|link]].";
        $cleanHtml = $renderer->render($dirty);

        $this->assertStringNotContainsString('<script>', $cleanHtml);
        $this->assertStringNotContainsString('javascript:', $cleanHtml);
        $this->assertStringContainsString('class="wikilink"', $cleanHtml);
        $this->assertStringContainsString('Target Note', $cleanHtml);
    }

    public function test_renders_callouts_toggles_tables_and_dividers(): void
    {
        $renderer = new MarkdownServerRenderer();

        $markdown = "> [!NOTE] Callout body\n\n<details><summary>Summary</summary>Details body</details>\n\n| H1 | H2 |\n| --- | --- |\n| C1 | C2 |\n\n---";
        $html = $renderer->render($markdown);

        $this->assertStringContainsString('class="callout"', $html);
        $this->assertStringContainsString('data-callout-type="note"', $html);
        $this->assertStringContainsString('<details>', $html);
        $this->assertStringContainsString('<summary>', $html);
        $this->assertStringContainsString('<table>', $html);
        $this->assertStringContainsString('<hr', $html);
    }

    public function test_strips_obsidian_comments_from_rendered_output(): void
    {
        $renderer = new MarkdownServerRenderer();

        $markdown = "# Title\n\n%% internal note %%\n\nVisible content.";
        $html = $renderer->render($markdown);

        $this->assertStringNotContainsString('internal note', $html);
        $this->assertStringNotContainsString('%%', $html);
        $this->assertStringContainsString('Visible content.', $html);
    }

    public function test_strips_multiline_obsidian_comments(): void
    {
        $renderer = new MarkdownServerRenderer();

        $markdown = "Before.\n\n%%\nsecret line one\nsecret line two\n%%\n\nAfter.";
        $html = $renderer->render($markdown);

        $this->assertStringNotContainsString('secret line', $html);
        $this->assertStringContainsString('Before.', $html);
        $this->assertStringContainsString('After.', $html);
    }

    public function test_latex_and_mermaid_render_as_plain_code_by_default(): void
    {
        config(['jotter.rendering.katex_mermaid_enabled' => false]);
        $renderer = new MarkdownServerRenderer();

        $markdown = '$$E=mc^2$$'."\n\n```mermaid\ngraph TD; A-->B;\n```";
        $html = $renderer->render($markdown);

        $this->assertStringNotContainsString('class="jotter-math"', $html);
        $this->assertStringNotContainsString('class="mermaid"', $html);
        $this->assertStringContainsString('E=mc^2', $html);
        $this->assertStringContainsString('<pre><code', $html);
        $this->assertStringContainsString('graph TD', $html);
    }

    public function test_latex_and_mermaid_render_with_hydration_markup_when_enabled(): void
    {
        config(['jotter.rendering.katex_mermaid_enabled' => true]);
        $renderer = new MarkdownServerRenderer();

        $markdown = '$$E=mc^2$$'."\n\n```mermaid\ngraph TD; A-->B;\n```";
        $html = $renderer->render($markdown);

        $this->assertStringContainsString('class="jotter-math"', $html);
        $this->assertStringContainsString('E=mc^2', $html);
        $this->assertStringContainsString('class="mermaid"', $html);
        $this->assertStringContainsString('graph TD', $html);
    }

    public function test_math_and_mermaid_markup_is_escaped_when_enabled(): void
    {
        config(['jotter.rendering.katex_mermaid_enabled' => true]);
        $renderer = new MarkdownServerRenderer();

        $html = $renderer->render('$$<script>alert(1)</script>$$');

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('class="jotter-math"', $html);
    }

    public function test_dataview_code_block_degrades_to_readable_code_without_crashing(): void
    {
        $renderer = new MarkdownServerRenderer();

        $markdown = "# Notes\n\n```dataview\nLIST FROM #project WHERE status = \"active\"\n```\n\nAfter.";
        $html = $renderer->render($markdown);

        $this->assertStringContainsString('<pre><code', $html);
        $this->assertStringContainsString('LIST FROM', $html);
        $this->assertStringContainsString('#project', $html);
        $this->assertStringContainsString('After.', $html);
    }

    public function test_tasks_plugin_emoji_syntax_renders_as_readable_text_without_crashing(): void
    {
        $renderer = new MarkdownServerRenderer();

        $markdown = "- [ ] Do thing \u{1F4C5} 2026-08-01 \u{1F501} every week\n- [x] Done thing \u{2705} 2026-07-01";
        $html = $renderer->render($markdown);

        // No exception, and every character of the line -- including the
        // Tasks-plugin emoji annotations -- survives. Checkbox rendering itself
        // is covered by test_renders_gfm_task_list_checkboxes below.
        $this->assertStringContainsString('<li', $html);
        $this->assertStringContainsString('Do thing', $html);
        $this->assertStringContainsString("\u{1F4C5}", $html);
        $this->assertStringContainsString('2026-08-01', $html);
        $this->assertStringContainsString('every week', $html);
        $this->assertStringContainsString("\u{2705}", $html);
    }

    public function test_renders_gfm_task_list_checkboxes(): void
    {
        $renderer = new MarkdownServerRenderer();

        $html = $renderer->render("- [ ] Open task\n- [x] Done task");

        $this->assertStringContainsString('type="checkbox"', $html);
        $this->assertStringContainsString('Open task', $html);
        $this->assertStringContainsString('Done task', $html);
        $this->assertMatchesRegularExpression(
            '/<input checked="" disabled="" type="checkbox">\s*Done task/',
            $html,
        );
        $this->assertMatchesRegularExpression(
            '/<input disabled="" type="checkbox">\s*Open task/',
            $html,
        );
    }
}
