<?php

namespace App\Domain\Vault;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Parses Obsidian-style Markdown with optional YAML front-matter.
 */
final class MarkdownDocument
{
    /**
     * @param  array<string, mixed>  $frontmatter
     * @param  list<string>  $tags
     */
    public function __construct(
        public readonly string $raw,
        public readonly array $frontmatter,
        public readonly string $body,
        public readonly string $title,
        public readonly array $tags,
        public readonly string $contentHash,
        public readonly string $searchContent,
    ) {}

    public static function parse(string $raw, string $fallbackTitle): self
    {
        [$frontmatter, $body] = self::splitFrontMatter($raw);
        $title = self::resolveTitle($frontmatter, $body, $fallbackTitle);
        $tags = self::extractTags($frontmatter);

        return new self(
            raw: $raw,
            frontmatter: $frontmatter,
            body: $body,
            title: $title,
            tags: $tags,
            contentHash: hash('sha256', $raw),
            searchContent: self::buildSearchContent($body),
        );
    }

    /**
     * @param  array<string, mixed>  $frontmatter
     */
    public static function compose(array $frontmatter, string $body): string
    {
        $body = self::normalizeNewlines($body);

        if ($frontmatter === []) {
            return $body;
        }

        $yaml = Yaml::dump($frontmatter, 4, 2, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE);
        $yaml = rtrim($yaml, "\n");

        return "---\n{$yaml}\n---\n".$body;
    }

    /**
     * @return array{0: array<string, mixed>, 1: string}
     */
    private static function splitFrontMatter(string $raw): array
    {
        $normalized = self::normalizeNewlines($raw);

        if (! str_starts_with($normalized, "---\n") && $normalized !== '---') {
            return [[], $normalized];
        }

        $offset = 4; // after opening ---\n
        $closing = strpos($normalized, "\n---", $offset);

        if ($closing === false) {
            return [[], $normalized];
        }

        $yamlBlock = substr($normalized, $offset, $closing - $offset);
        $rest = substr($normalized, $closing + 4); // past \n---
        if (str_starts_with($rest, "\n")) {
            $rest = substr($rest, 1);
        }

        try {
            $parsed = Yaml::parse($yamlBlock);
        } catch (ParseException) {
            return [[], $normalized];
        }

        if (! is_array($parsed)) {
            return [[], $normalized];
        }

        /** @var array<string, mixed> $parsed */
        return [$parsed, $rest];
    }

    /**
     * @param  array<string, mixed>  $frontmatter
     */
    private static function resolveTitle(array $frontmatter, string $body, string $fallbackTitle): string
    {
        $fromMatter = $frontmatter['title'] ?? null;
        if (is_string($fromMatter) && trim($fromMatter) !== '') {
            return trim($fromMatter);
        }

        if (preg_match('/^#\s+(.+)$/m', $body, $matches) === 1) {
            return trim($matches[1]);
        }

        return $fallbackTitle;
    }

    /**
     * @param  array<string, mixed>  $frontmatter
     * @return list<string>
     */
    private static function extractTags(array $frontmatter): array
    {
        if (! array_key_exists('tags', $frontmatter)) {
            return [];
        }

        $rawTags = $frontmatter['tags'];
        $tags = [];

        if (is_string($rawTags)) {
            $parts = preg_split('/\s*,\s*/', $rawTags) ?: [];
            foreach ($parts as $part) {
                $tags[] = $part;
            }
        } elseif (is_array($rawTags)) {
            foreach ($rawTags as $part) {
                if (is_string($part) || is_numeric($part)) {
                    $tags[] = (string) $part;
                }
            }
        }

        $normalized = [];
        foreach ($tags as $tag) {
            $tag = trim($tag);
            $tag = ltrim($tag, '#');
            if ($tag === '') {
                continue;
            }
            $normalized[$tag] = $tag;
        }

        return array_values($normalized);
    }

    private static function buildSearchContent(string $body): string
    {
        // Q1 default: rebuildable FULLTEXT projection of note body text only (not canonical).
        return trim($body);
    }

    private static function normalizeNewlines(string $value): string
    {
        return str_replace(["\r\n", "\r"], "\n", $value);
    }
}
