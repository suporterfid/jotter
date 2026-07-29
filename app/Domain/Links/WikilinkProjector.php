<?php

namespace App\Domain\Links;

use App\Models\Note;
use App\Models\NoteLink;
use App\Models\Workspace;

/**
 * Maintains the rebuildable wikilink projection. Markdown remains on disk.
 */
final class WikilinkProjector
{
    public const TYPE = 'wikilink';

    public function __construct(
        private readonly WikilinkExtractor $extractor = new WikilinkExtractor,
    ) {}

    public function project(Workspace $workspace, Note $sourceNote, string $body): void
    {
        NoteLink::query()->where('source_note_id', $sourceNote->id)->delete();

        foreach ($this->extractor->extract($body) as $reference) {
            NoteLink::query()->create([
                'source_note_id' => $sourceNote->id,
                'target_ref' => $reference['target_ref'],
                'target_block' => $reference['target_block'],
                'target_note_id' => null,
                'type' => self::TYPE,
            ]);
        }

        $this->resolveWorkspaceLinks($workspace);
    }

    /**
     * Re-evaluate all workspace links using only the rebuildable MySQL index.
     * This intentionally performs no filesystem scan, so backlinks stay a DB query.
     */
    public function resolveWorkspaceLinks(Workspace $workspace): void
    {
        $notes = Note::query()
            ->where('workspace_id', $workspace->id)
            ->get(['id', 'path', 'title', 'frontmatter']);

        if ($notes->isEmpty()) {
            return;
        }

        $pathCandidates = [];
        $titleCandidates = [];
        $aliasCandidates = [];

        foreach ($notes as $note) {
            foreach ($this->pathKeys((string) $note->path) as $key) {
                $lowerKey = mb_strtolower($key, 'UTF-8');
                $pathCandidates[$lowerKey][] = $note->id;
            }

            $title = trim((string) $note->title);
            if ($title !== '') {
                $lowerTitle = mb_strtolower($title, 'UTF-8');
                $titleCandidates[$lowerTitle][] = $note->id;
            }

            foreach ($this->extractAliases($note->frontmatter) as $alias) {
                $lowerAlias = mb_strtolower($alias, 'UTF-8');
                $aliasCandidates[$lowerAlias][] = $note->id;
            }
        }

        NoteLink::query()
            ->where('type', self::TYPE)
            ->whereIn('source_note_id', $notes->pluck('id'))
            ->orderBy('id')
            ->each(function (NoteLink $link) use ($pathCandidates, $titleCandidates, $aliasCandidates): void {
                $targetKey = mb_strtolower($this->extractor->targetKey($link->target_ref), 'UTF-8');
                $candidates = $pathCandidates[$targetKey] ?? $titleCandidates[$targetKey] ?? $aliasCandidates[$targetKey] ?? [];
                $candidateIds = array_values(array_unique($candidates));
                $targetNoteId = count($candidateIds) === 1 ? $candidateIds[0] : null;

                if ($link->target_note_id !== $targetNoteId) {
                    $link->update(['target_note_id' => $targetNoteId]);
                }
            });
    }

    /**
     * @param  array<string, mixed>|null  $frontmatter
     * @return list<string>
     */
    private function extractAliases(?array $frontmatter): array
    {
        if ($frontmatter === null || ! array_key_exists('aliases', $frontmatter)) {
            return [];
        }

        $raw = $frontmatter['aliases'];
        $aliases = [];

        if (is_string($raw)) {
            foreach (preg_split('/\s*,\s*/', $raw) ?: [] as $part) {
                $aliases[] = $part;
            }
        } elseif (is_array($raw)) {
            foreach ($raw as $part) {
                if (is_string($part) || is_numeric($part)) {
                    $aliases[] = (string) $part;
                }
            }
        }

        $normalized = [];
        foreach ($aliases as $alias) {
            $alias = trim($alias);
            if ($alias === '') {
                continue;
            }
            $normalized[$alias] = $alias;
        }

        return array_values($normalized);
    }

    /**
     * @return list<string>
     */
    private function pathKeys(string $path): array
    {
        $path = trim(str_replace('\\', '/', $path), '/');
        if ($path === '') {
            return [];
        }

        $keys = [$path];
        if (str_ends_with(strtolower($path), '.md')) {
            $keys[] = substr($path, 0, -3);
        }

        return array_values(array_unique($keys));
    }
}
