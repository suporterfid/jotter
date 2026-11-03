<?php

namespace App\Domain\Mcp;

/**
 * Appends Markdown to a note, either at the end or at the end of the section
 * opened by an ATX heading (`## Heading`). The section ends at the next
 * heading of the same or a higher level; headings inside fenced code blocks
 * are ignored. A missing heading is created at the end of the note. Front
 * matter is never touched, and blocks are separated by one blank line.
 */
final class MarkdownSectionAppender
{
    public function append(string $document, string $addition, ?string $heading = null): string
    {
        $addition = trim($addition, "\r\n");
        $eol = str_contains($document, "\r\n") ? "\r\n" : "\n";

        if ($heading === null || trim($heading) === '') {
            return $this->joinBlocks(rtrim($document, "\r\n"), $addition, $eol).$eol;
        }

        $heading = trim($heading);
        $lines = preg_split('/\r\n|\n/', $document) ?: [];
        [$start, $level] = $this->findHeading($lines, $heading);

        if ($start === null) {
            $section = '## '.$heading.$eol.$eol.$addition;

            return $this->joinBlocks(rtrim($document, "\r\n"), $section, $eol).$eol;
        }

        $end = $this->sectionEnd($lines, $start, $level);
        $before = array_slice($lines, 0, $end);
        $after = array_slice($lines, $end);

        // Trim trailing blank lines of the section before inserting.
        while ($before !== [] && trim((string) end($before)) === '' && count($before) - 1 > $start) {
            array_pop($before);
        }

        $result = implode($eol, $before).$eol.$eol.$addition;
        if ($after !== []) {
            $result .= $eol.$eol.ltrim(implode($eol, $after), "\r\n");
        }

        return rtrim($result, "\r\n").$eol;
    }

    /**
     * @param list<string> $lines
     * @return array{0: int|null, 1: int}
     */
    private function findHeading(array $lines, string $heading): array
    {
        $inFence = false;
        $inFrontMatter = isset($lines[0]) && trim($lines[0]) === '---';
        foreach ($lines as $index => $line) {
            if ($inFrontMatter) {
                if ($index > 0 && in_array(trim($line), ['---', '...'], true)) {
                    $inFrontMatter = false;
                }

                continue;
            }
            if ($this->isFence($line)) {
                $inFence = ! $inFence;

                continue;
            }
            if ($inFence) {
                continue;
            }
            if (preg_match('/^(#{1,6})\s+(.*?)\s*#*\s*$/', $line, $matches) === 1
                && mb_strtolower(trim($matches[2])) === mb_strtolower($heading)) {
                return [$index, strlen($matches[1])];
            }
        }

        return [null, 0];
    }

    /**
     * @param list<string> $lines
     */
    private function sectionEnd(array $lines, int $start, int $level): int
    {
        $inFence = false;
        $count = count($lines);
        for ($index = $start + 1; $index < $count; $index++) {
            $line = $lines[$index];
            if ($this->isFence($line)) {
                $inFence = ! $inFence;

                continue;
            }
            if (! $inFence && preg_match('/^(#{1,6})\s/', $line, $matches) === 1 && strlen($matches[1]) <= $level) {
                return $index;
            }
        }

        return $count;
    }

    private function isFence(string $line): bool
    {
        return preg_match('/^\s{0,3}(```|~~~)/', $line) === 1;
    }

    private function joinBlocks(string $existing, string $addition, string $eol): string
    {
        if (trim($existing) === '') {
            return $addition;
        }

        return $existing.$eol.$eol.$addition;
    }
}
