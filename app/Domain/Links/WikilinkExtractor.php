<?php

namespace App\Domain\Links;

/**
 * Extracts Obsidian-style wikilinks from Markdown bodies without rendering them.
 */
final class WikilinkExtractor
{
    /**
     * @return list<array{target_ref: string, target_key: string}>
     */
    public function extract(string $body): array
    {
        preg_match_all('/(?<!\\\\)\[\[([^\[\]\r\n]+)\]\]/u', $body, $matches);

        $references = [];
        foreach ($matches[1] as $rawReference) {
            $targetRef = trim($rawReference);
            $targetKey = $this->targetKey($targetRef);
            if ($targetRef === '' || $targetKey === '') {
                continue;
            }

            $references[$targetRef] = [
                'target_ref' => $targetRef,
                'target_key' => $targetKey,
            ];
        }

        return array_values($references);
    }

    public function targetKey(string $targetRef): string
    {
        $target = trim(explode('|', $targetRef, 2)[0]);

        return trim(explode('#', $target, 2)[0]);
    }
}
