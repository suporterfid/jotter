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
    public function search(Workspace $workspace, SearchCriteria|string $criteria): array
    {
        if (is_string($criteria)) {
            $criteria = new SearchCriteria(query: $criteria);
        }

        $builder = Note::query()->where('notes.workspace_id', $workspace->id);

        if ($criteria->query !== null && $criteria->query !== '') {
            $match = 'MATCH(notes.title, notes.search_content) AGAINST (? IN NATURAL LANGUAGE MODE)';
            $builder->whereRaw($match, [$criteria->query])
                ->select(['notes.id', 'notes.path', 'notes.title', 'notes.search_content'])
                ->selectRaw($match.' AS relevance', [$criteria->query])
                ->orderByDesc('relevance')
                ->orderBy('notes.id');
        } else {
            $builder->select(['notes.id', 'notes.path', 'notes.title', 'notes.search_content', 'notes.updated_at'])
                ->selectRaw('0.0 AS relevance')
                ->orderByDesc('notes.updated_at')
                ->orderBy('notes.id');
        }

        if ($criteria->title !== null && $criteria->title !== '') {
            $builder->where('notes.title', 'LIKE', '%'.$criteria->title.'%');
        }

        if (! empty($criteria->tags)) {
            foreach ($criteria->tags as $tag) {
                $cleanTag = ltrim($tag, '#');
                $builder->whereHas('tags', function ($q) use ($cleanTag) {
                    $q->where('name', $cleanTag);
                });
            }
        }

        if ($criteria->modifiedAfter !== null && $criteria->modifiedAfter !== '') {
            $builder->where('notes.updated_at', '>=', $criteria->modifiedAfter);
        }

        if ($criteria->modifiedBefore !== null && $criteria->modifiedBefore !== '') {
            $builder->where('notes.updated_at', '<=', $criteria->modifiedBefore);
        }

        $perPage = min(max(1, $criteria->perPage), self::RESULT_LIMIT);
        $offset = (max(1, $criteria->page) - 1) * $perPage;

        return $builder
            ->offset($offset)
            ->limit($perPage)
            ->get()
            ->map(fn (Note $note): array => [
                'id' => $note->id,
                'path' => $note->path,
                'title' => $note->title,
                'snippet' => $this->snippet($note->title, $note->search_content ?? '', $criteria->query ?? ''),
                'relevance' => (float) ($note->relevance ?? 0.0),
            ])
            ->all();
    }

    private function snippet(string $title, string $content, string $query): string
    {
        $title = trim((string) preg_replace('/\s+/u', ' ', $title));
        $content = trim((string) preg_replace('/\s+/u', ' ', $content));

        if ($query !== '') {
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
