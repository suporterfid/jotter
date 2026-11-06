<?php

namespace App\Domain\Semantic;

/**
 * Splits a note's plain text into overlapping chunks of about `chunk_chars`,
 * on paragraph boundaries where possible. Each chunk starts with the note's
 * title so a chunk alone still says what it is about.
 */
final class Chunker
{
    /**
     * @return list<string>
     */
    public function chunks(string $title, string $text): array
    {
        $size = max(200, (int) config('jotter.semantic.chunk_chars', 1500));
        $overlap = max(0, min($size - 100, (int) config('jotter.semantic.chunk_overlap_chars', 200)));
        $prefix = trim($title) === '' ? '' : trim($title)."\n\n";

        $text = trim((string) preg_replace("/[ \t]+/u", ' ', str_replace("\r\n", "\n", $text)));
        if ($text === '') {
            return $prefix === '' ? [] : [trim($prefix)];
        }

        $paragraphs = preg_split("/\n{2,}/u", $text) ?: [$text];
        $chunks = [];
        $current = '';
        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                continue;
            }
            // A paragraph longer than a chunk is cut into pieces of $size.
            while (mb_strlen($paragraph) > $size) {
                if ($current !== '') {
                    $chunks[] = $current;
                    $current = '';
                }
                $chunks[] = mb_substr($paragraph, 0, $size);
                $paragraph = mb_substr($paragraph, $size - $overlap);
            }
            if ($current !== '' && mb_strlen($current) + 2 + mb_strlen($paragraph) > $size) {
                $chunks[] = $current;
                $tail = $overlap > 0 ? mb_substr($current, -$overlap) : '';
                $current = $tail === '' ? $paragraph : $tail."\n\n".$paragraph;
            } else {
                $current = $current === '' ? $paragraph : $current."\n\n".$paragraph;
            }
        }
        if ($current !== '') {
            $chunks[] = $current;
        }

        return array_map(static fn (string $chunk): string => $prefix.$chunk, $chunks);
    }
}
