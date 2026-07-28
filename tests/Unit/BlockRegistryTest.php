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
    }
}
