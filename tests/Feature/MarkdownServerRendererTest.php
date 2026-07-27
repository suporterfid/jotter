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
}
