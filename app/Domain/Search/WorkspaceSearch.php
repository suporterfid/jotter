<?php

namespace App\Domain\Search;

use App\Models\Note;
use App\Models\Workspace;

final class WorkspaceSearch
{
    private const RESULT_LIMIT = 50;

    private const SNIPPET_LENGTH = 240;

    /**
     * @return list<array{id: int, path: string, title: string, snippet: string, relevance: float}>
     */
    public function search(Workspace $workspace, string $query): array
    {
        $match = 'MATCH(title, search_content) AGAINST (? IN NATURAL LANGUAGE MODE)';

        return Note::query()
            ->where('workspace_id', $workspace->id)
            ->whereRaw($match, [$query])
            ->select(['id', 'path', 'title', 'search_content'])
            ->selectRaw($match.' AS relevance', [$query])
            ->orderByDesc('relevance')
            ->orderBy('id')
            ->limit(self::RESULT_LIMIT)
            ->get()
            ->map(fn (Note $note): array => [
                'id' => $note->id,
                'path' => $note->path,
                'title' => $note->title,
                'snippet' => $this->snippet($note->title, $note->search_content ?? '', $query),
                'relevance' => (float) $note->relevance,
            ])
            ->all();
    }

    private function snippet(string $title, string $content, string $query): string
    {
        $title = trim((string) preg_replace('/\s+/u', ' ', $title));
        $content = trim((string) preg_replace('/\s+/u', ' ', $content));

        foreach ($this->terms($query) as $term) {
            $titlePosition = mb_stripos($title, $term);
            if ($titlePosition !== false) {
                return $this->excerpt($title, $titlePosition);
            }
        }

        $position = null;
        foreach ($this->terms($query) as $term) {
            $termPosition = mb_stripos($content, $term);
            if ($termPosition !== false && ($position === null || $termPosition < $position)) {
                $position = $termPosition;
            }
        }

        if ($position !== null) {
            return $this->excerpt($content, $position);
        }

        if ($content !== '') {
            return mb_strimwidth($content, 0, self::SNIPPET_LENGTH, '…');
        }

        return mb_strimwidth($title, 0, self::SNIPPET_LENGTH, '…');
    }

    /**
     * @return list<string>
     */
    private function terms(string $query): array
    {
        $terms = preg_split('/[^\p{L}\p{N}_]+/u', $query) ?: [];

        return array_values(array_unique(array_filter($terms, fn (string $term): bool => $term !== '')));
    }

    private function excerpt(string $value, int $position): string
    {
        $start = max(0, $position - 80);
        $prefix = $start > 0 ? '…' : '';
        $length = self::SNIPPET_LENGTH - mb_strlen($prefix);
        $snippet = mb_strimwidth(mb_substr($value, $start), 0, $length, '…');

        return $prefix.$snippet;
    }
}
