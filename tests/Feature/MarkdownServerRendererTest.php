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
}
