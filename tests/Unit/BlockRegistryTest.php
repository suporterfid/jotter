<?php

namespace Tests\Unit;

use App\Domain\Vault\BlockRegistry;
use PHPUnit\Framework\TestCase;

class BlockRegistryTest extends TestCase
{
    public function test_block_registry_defines_all_required_blocks(): void
    {
        $defs = BlockRegistry::definitions();

        $this->assertArrayHasKey('task_list', $defs);
        $this->assertArrayHasKey('code_block', $defs);
        $this->assertArrayHasKey('wikilink', $defs);
        $this->assertArrayHasKey('callout', $defs);
        $this->assertArrayHasKey('toggle', $defs);
        $this->assertArrayHasKey('table', $defs);
        $this->assertArrayHasKey('divider', $defs);
        $this->assertArrayHasKey('embed', $defs);
        $this->assertArrayHasKey('external_embed', $defs);

        $this->assertSame('https://example.com/embed', $defs['external_embed']['syntax']);
        $this->assertSame(['iframe', 'a'], $defs['external_embed']['allowed_tags']);
        $this->assertSame(
            ['class', 'src', 'title', 'sandbox', 'referrerpolicy', 'loading', 'href', 'target', 'rel'],
            $defs['external_embed']['allowed_attributes']
        );
        $this->assertSame(['label' => 'External Embed', 'icon' => 'globe'], $defs['external_embed']['slash_menu']);
    }

    public function test_allowed_tags_and_attributes_derivation(): void
    {
        $tags = BlockRegistry::allowedTags();
        $attrs = BlockRegistry::allowedAttributes();

        $this->assertContains('a', $tags);
        $this->assertContains('pre', $tags);
        $this->assertContains('details', $tags);
        $this->assertContains('table', $tags);

        $this->assertContains('data-target', $attrs);
        $this->assertContains('data-language', $attrs);
        $this->assertContains('class', $attrs);
        $this->assertContains('data-embed-status', $attrs);
        $this->assertContains('sandbox', $attrs);
        $this->assertContains('referrerpolicy', $attrs);
    }
}
