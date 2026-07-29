<?php

namespace App\Domain\Links;

/**
 * Extracts Obsidian-style wikilinks from Markdown bodies without rendering them.
 */
final class WikilinkExtractor
{
    /**
     * @return list<array{target_ref: string, target_key: string, target_block: ?string}>
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
                'target_block' => $this->targetBlock($targetRef),
            ];
        }

        return array_values($references);
    }

    public function targetKey(string $targetRef): string
    {
        $target = trim(explode('|', $targetRef, 2)[0]);

        return trim(explode('#', $target, 2)[0]);
    }

    /**
     * A fragment introduced with `^` (e.g. `[[note#^myblock]]`) is a block
     * reference, distinct from a `#heading` anchor. Returns the block id
     * without its leading `^`, or null when the reference is not a block ref.
     */
    public function targetBlock(string $targetRef): ?string
    {
        $target = trim(explode('|', $targetRef, 2)[0]);
        $parts = explode('#', $target, 2);

        if (count($parts) !== 2) {
            return null;
        }

        $fragment = trim($parts[1]);
        if (! str_starts_with($fragment, '^')) {
            return null;
        }

        $blockId = trim(substr($fragment, 1));

        return $blockId === '' ? null : $blockId;
    }
}
