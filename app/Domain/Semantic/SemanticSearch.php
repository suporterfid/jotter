<?php

namespace App\Domain\Semantic;

use App\Domain\Auth\AuthenticatedSubject;
use App\Domain\Auth\NoteAccess;
use App\Domain\Search\WorkspaceSearch;
use App\Domain\Semantic\Contracts\EmbeddingProvider;
use App\Models\Note;
use App\Models\NoteEmbedding;
use App\Models\Workspace;

/**
 * Search by meaning over the chunks of one workspace, limited to the notes the
 * subject may see (NoteAccess; the credential's workspace list was checked by
 * the caller). Similarity is a dot product of normalised vectors computed in
 * PHP over at most `max_chunks_scanned` chunks (MySQL 8 has no vector index).
 *
 * Falls back to full-text search, and says so in `mode`, when semantic search
 * is off, the workspace has not opted in or has no index yet, or the
 * embedding call for the query fails.
 */
final class SemanticSearch
{
    public const MAX_LIMIT = 25;

    public function __construct(
        private readonly EmbeddingProvider $provider,
        private readonly NoteAccess $noteAccess,
        private readonly WorkspaceSearch $fullText,
    ) {}

    /**
     * @return array{mode: string, reason: string|null, results: list<array<string, mixed>>}
     */
    public function search(AuthenticatedSubject $subject, Workspace $workspace, string $query, int $limit = 10): array
    {
        $limit = max(1, min($limit, self::MAX_LIMIT));
        $query = trim($query);

        if (! SemanticIndexer::enabled()) {
            return $this->fallback($subject, $workspace, $query, $limit, 'Semantic search is not enabled on this server.');
        }
        if (! $workspace->semantic_search) {
            return $this->fallback($subject, $workspace, $query, $limit, 'Semantic search is not enabled for this workspace.');
        }

        $model = $this->provider->model();
        $visible = $this->noteAccess->scopeVisible(Note::query(), $subject, $workspace->id)->select('notes.id');
        $max = max(1, (int) config('jotter.semantic.max_chunks_scanned', 20000));
        $rows = NoteEmbedding::query()
            ->where('workspace_id', $workspace->id)
            ->where('model', $model)
            ->whereIn('note_id', $visible)
            ->orderByDesc('note_id')
            ->limit($max)
            ->get(['note_id', 'chunk_index', 'chunk_text', 'vector']);
        if ($rows->isEmpty()) {
            return $this->fallback($subject, $workspace, $query, $limit, 'The semantic index of this workspace is empty or still being built.');
        }

        try {
            $vectors = $this->provider->embed([$query], (int) config('jotter.semantic.query_timeout_seconds', 5));
        } catch (EmbeddingFailed $failure) {
            return $this->fallback($subject, $workspace, $query, $limit, $failure->getMessage());
        }
        $needle = VectorCodec::normalize($vectors[0] ?? []);

        // Best chunk per note.
        $best = [];
        foreach ($rows as $row) {
            $score = VectorCodec::dot($needle, VectorCodec::unpack((string) $row->vector));
            if (! isset($best[$row->note_id]) || $score > $best[$row->note_id]['score']) {
                $best[$row->note_id] = ['score' => $score, 'chunk' => (string) $row->chunk_text];
            }
        }
        uasort($best, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        $top = array_slice($best, 0, $limit, true);

        $notes = Note::query()->whereIn('id', array_keys($top))->get(['id', 'path', 'title'])->keyBy('id');
        $results = [];
        foreach ($top as $noteId => $match) {
            $note = $notes->get($noteId);
            if ($note === null) {
                continue;
            }
            $results[] = [
                'id' => $note->id,
                'path' => $note->path,
                'title' => $note->title,
                'snippet' => mb_strimwidth(trim((string) preg_replace('/\s+/u', ' ', $match['chunk'])), 0, 240, '…'),
                'score' => round($match['score'], 4),
            ];
        }

        return ['mode' => 'semantic', 'reason' => null, 'results' => $results];
    }

    /**
     * @return array{mode: string, reason: string|null, results: list<array<string, mixed>>}
     */
    private function fallback(AuthenticatedSubject $subject, Workspace $workspace, string $query, int $limit, string $reason): array
    {
        $results = array_slice($this->fullText->search($workspace, $query, $subject), 0, $limit);

        return ['mode' => 'fulltext', 'reason' => $reason, 'results' => array_values($results)];
    }
}
