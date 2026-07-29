<?php

namespace Tests\Unit;

use App\Domain\Links\WikilinkExtractor;
use PHPUnit\Framework\TestCase;

final class WikilinkExtractorTest extends TestCase
{
    public function test_block_reference_is_distinguished_from_heading_anchor(): void
    {
        $extractor = new WikilinkExtractor;

        $this->assertSame('myblock', $extractor->targetBlock('note#^myblock'));
        $this->assertNull($extractor->targetBlock('note#heading'));
        $this->assertNull($extractor->targetBlock('note'));
    }

    public function test_extract_captures_target_block_alongside_target_key(): void
    {
        $extractor = new WikilinkExtractor;

        $references = $extractor->extract('[[note#^myblock]] and [[note#heading]] and [[note]]');

        $byRef = [];
        foreach ($references as $reference) {
            $byRef[$reference['target_ref']] = $reference;
        }

        $this->assertSame('note', $byRef['note#^myblock']['target_key']);
        $this->assertSame('myblock', $byRef['note#^myblock']['target_block']);

        $this->assertSame('note', $byRef['note#heading']['target_key']);
        $this->assertNull($byRef['note#heading']['target_block']);

        $this->assertSame('note', $byRef['note']['target_key']);
        $this->assertNull($byRef['note']['target_block']);
    }
}
