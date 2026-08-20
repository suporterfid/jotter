<?php

namespace Tests\Unit;

use App\Domain\Vault\NoteRevisionDiff;
use InvalidArgumentException;
use Tests\TestCase;

final class NoteRevisionDiffTest extends TestCase
{
    public function test_it_emits_context_removed_and_added_lines_with_line_numbers(): void
    {
        $diff = (new NoteRevisionDiff())->compare("same\nold", "same\nnew");

        $this->assertTrue($diff['changed']);
        $this->assertSame([
            ['type' => 'context', 'from_line' => 1, 'to_line' => 1, 'text' => 'same'],
            ['type' => 'removed', 'from_line' => 2, 'to_line' => null, 'text' => 'old'],
            ['type' => 'added', 'from_line' => null, 'to_line' => 2, 'text' => 'new'],
        ], $diff['lines']);
    }

    public function test_identical_contents_have_no_lines_and_trailing_newlines_are_ignored(): void
    {
        $diff = (new NoteRevisionDiff())->compare("same\n", "same\r\n");

        $this->assertFalse($diff['changed']);
        $this->assertSame([], $diff['lines']);
    }

    public function test_it_handles_insertions_and_deletions_without_reordering_context(): void
    {
        $service = new NoteRevisionDiff();

        $insertion = $service->compare("a\nc", "a\nb\nc");
        $this->assertSame(['type' => 'added', 'from_line' => null, 'to_line' => 2, 'text' => 'b'], $insertion['lines'][1]);

        $deletion = $service->compare("a\nb\nc", "a\nc");
        $this->assertSame(['type' => 'removed', 'from_line' => 2, 'to_line' => null, 'text' => 'b'], $deletion['lines'][1]);
    }

    public function test_it_rejects_inputs_above_the_line_limit(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new NoteRevisionDiff())->compare("one\ntwo", "one\ntwo", maxLines: 1);
    }
}
