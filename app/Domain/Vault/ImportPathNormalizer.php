<?php

namespace App\Domain\Vault;

/**
 * Rewrites archive entry names (and Notion Markdown links) so exported vaults
 * land in Jotter as clean paths. Pure string work; the security guards in
 * VaultExtractor still validate every resulting path.
 */
final class ImportPathNormalizer
{
    /** Notion appends a 32-hex page id to every exported file and folder name. */
    private const NOTION_ID = '/\s+[0-9a-f]{32}(?=(\.[A-Za-z0-9]+)?$)/';

    /** Directories Obsidian keeps for itself; not notes. */
    private const OBSIDIAN_SKIP_DIRS = ['.obsidian', '.trash'];

    /**
     * When every entry lives under one top-level folder (Obsidian vault folder,
     * Notion `Export-<uuid>`), return that prefix so it can be stripped.
     *
     * @param  list<string>  $names
     */
    public function detectRootDirectory(array $names): ?string
    {
        $roots = [];
        foreach ($names as $name) {
            $name = ltrim($name, '/');
            if ($name === '' || ! str_contains($name, '/')) {
                return null;
            }
            $roots[strstr($name, '/', true)] = true;
        }

        return count($roots) === 1 ? array_key_first($roots).'/' : null;
    }

    /**
     * Returns the normalized entry name, or null when the entry must be skipped.
     */
    public function normalize(ImportSource $source, string $name, ?string $rootPrefix): ?string
    {
        $name = ltrim($name, '/');
        if ($rootPrefix !== null && str_starts_with($name, $rootPrefix)) {
            $name = substr($name, strlen($rootPrefix));
        }
        if ($name === '') {
            return null;
        }

        return match ($source) {
            ImportSource::GENERIC => $name,
            ImportSource::OBSIDIAN => $this->normalizeObsidian($name),
            ImportSource::NOTION => $this->normalizeNotion($name),
        };
    }

    /**
     * Notion Markdown links point at URL-encoded, id-suffixed files. Turn links
     * to other exported pages into wikilinks (which Jotter indexes) and clean
     * attachment links.
     */
    public function rewriteNotionMarkdown(string $markdown): string
    {
        return (string) preg_replace_callback('/\]\(([^)\s]+)\)/', function (array $match): string {
            $target = $match[1];
            if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $target) === 1 || str_starts_with($target, '#')) {
                return $match[0];
            }

            $decoded = rawurldecode($target);
            $anchor = '';
            if (str_contains($decoded, '#')) {
                [$decoded, $anchor] = explode('#', $decoded, 2);
            }
            $clean = $this->stripNotionIds($decoded);

            if (str_ends_with(strtolower($clean), '.md')) {
                return '](['.'['.substr($clean, 0, -3).']])';
            }

            return ']('.str_replace(' ', '%20', $clean).($anchor !== '' ? '#'.$anchor : '').')';
        }, $markdown);
    }

    private function normalizeObsidian(string $name): ?string
    {
        $first = strstr($name, '/', true) ?: $name;
        if (in_array($first, self::OBSIDIAN_SKIP_DIRS, true)) {
            return null;
        }

        return $name;
    }

    private function normalizeNotion(string $name): string
    {
        return $this->stripNotionIds($name);
    }

    private function stripNotionIds(string $path): string
    {
        $segments = array_map(
            fn (string $segment): string => trim((string) preg_replace(self::NOTION_ID, '', $segment)),
            explode('/', $path),
        );

        return implode('/', array_filter($segments, static fn (string $segment): bool => $segment !== ''));
    }
}
