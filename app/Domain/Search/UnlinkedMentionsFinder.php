<?php

namespace App\Domain\Search;

use App\Models\Note;
use App\Models\NoteLink;
use App\Models\Workspace;

/**
 * Finds notes in a workspace whose text mentions a target note's title/alias
 * without an explicit [[wikilink]] to it -- Obsidian's "unlinked mentions".
 * Bounded to a single workspace-scoped, indexed query (spec §4): no per-request
 * filesystem/vault scan.
 */
final class UnlinkedMentionsFinder
{
    private const RESULT_LIMIT = 50;

    private const MIN_PHRASE_LENGTH = 2;

    /**
     * @return list<array{id: int, path: string, title: string, matched_phrase: string, snippet: string}>
     */
    public function find(Workspace $workspace, Note $target): array
    {
        $phrases = $this->candidatePhrases($target);
        if ($phrases === []) {
            return [];
        }

        $alreadyLinkedSourceIds = NoteLink::query()
            ->where('target_note_id', $target->id)
            ->where('type', 'wikilink')
            ->pluck('source_note_id')
            ->all();

        $candidates = Note::query()
            ->where('workspace_id', $workspace->id)
            ->where('id', '!=', $target->id)
            ->whereNotIn('id', $alreadyLinkedSourceIds)
            ->where(function ($query) use ($phrases): void {
                foreach ($phrases as $phrase) {
                    $query->orWhere('title', 'LIKE', '%'.$this->escapeLike($phrase).'%')
                        ->orWhere('search_content', 'LIKE', '%'.$this->escapeLike($phrase).'%');
                }
            })
            ->limit(self::RESULT_LIMIT)
            ->get(['id', 'path', 'title', 'search_content']);

        $results = [];
        foreach ($candidates as $note) {
            $matchedPhrase = $this->firstMatchingPhrase($note, $phrases);
            if ($matchedPhrase === null) {
                continue;
            }

            $results[] = [
                'id' => $note->id,
                'path' => $note->path,
                'title' => $note->title,
                'matched_phrase' => $matchedPhrase,
                'snippet' => $this->snippet((string) $note->search_content, $matchedPhrase),
            ];
        }

        return $results;
    }

    /**
     * @return list<string>
     */
    private function candidatePhrases(Note $target): array
    {
        $phrases = [trim((string) $target->title)];

        $frontmatter = $target->frontmatter ?? [];
        if (array_key_exists('aliases', $frontmatter)) {
            $raw = $frontmatter['aliases'];
            if (is_string($raw)) {
                foreach (preg_split('/\s*,\s*/', $raw) ?: [] as $part) {
                    $phrases[] = $part;
                }
            } elseif (is_array($raw)) {
                foreach ($raw as $part) {
                    if (is_string($part) || is_numeric($part)) {
                        $phrases[] = (string) $part;
                    }
                }
            }
        }

        $normalized = [];
        foreach ($phrases as $phrase) {
            $phrase = trim($phrase);
            if (mb_strlen($phrase, 'UTF-8') < self::MIN_PHRASE_LENGTH) {
                continue;
            }
            $normalized[mb_strtolower($phrase, 'UTF-8')] = $phrase;
        }

        return array_values($normalized);
    }

    /**
     * @param  list<string>  $phrases
     */
    private function firstMatchingPhrase(Note $note, array $phrases): ?string
    {
        $haystack = mb_strtolower($note->title.' '.($note->search_content ?? ''), 'UTF-8');

        foreach ($phrases as $phrase) {
            if (mb_stripos($haystack, mb_strtolower($phrase, 'UTF-8'), 0, 'UTF-8') !== false) {
                return $phrase;
            }
        }

        return null;
    }

    private function snippet(string $content, string $phrase): string
    {
        $content = trim((string) preg_replace('/\s+/u', ' ', $content));
        $position = mb_stripos($content, $phrase);
        if ($position === false) {
            return mb_strimwidth($content, 0, 240, '…');
        }

        $start = max(0, $position - 80);
        $prefix = $start > 0 ? '…' : '';

        return $prefix.mb_strimwidth(mb_substr($content, $start), 0, 240 - mb_strlen($prefix), '…');
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
