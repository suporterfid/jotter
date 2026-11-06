<?php

namespace App\Domain\Semantic;

use App\Domain\Semantic\Contracts\EmbeddingProvider;
use App\Models\Note;
use App\Models\NoteEmbedding;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

/**
 * Keeps `note_embeddings` in step with the notes of opted-in workspaces. Run by
 * the scheduler (`semantic:index`), never from a web request: each run embeds
 * at most `$limit` changed notes, so it fits shared-hosting time limits, and
 * the next run continues. Rows of workspaces that opted out are deleted.
 */
final class SemanticIndexer
{
    private const MAX_CHUNKS_PER_NOTE = 50;

    public function __construct(
        private readonly EmbeddingProvider $provider,
        private readonly Chunker $chunker = new Chunker,
    ) {}

    public static function enabled(): bool
    {
        return (bool) config('jotter.semantic.enabled', false);
    }

    /**
     * @return array{indexed: int, chunks: int, removed: int, pending: int, failed: int, error: string|null}
     */
    public function run(int $limit, ?Workspace $only = null): array
    {
        $stats = ['indexed' => 0, 'chunks' => 0, 'removed' => $this->purgeOptedOut(), 'pending' => 0, 'failed' => 0, 'error' => null];
        if (! self::enabled()) {
            return $stats;
        }

        $workspaceIds = Workspace::query()->where('semantic_search', true)
            ->when($only !== null, static fn ($query) => $query->whereKey($only->id))
            ->pluck('id');
        if ($workspaceIds->isEmpty()) {
            return $stats;
        }

        // Rows of notes that no longer exist go with the note (FK cascade); rows
        // embedded from older content or another model are stale.
        $stale = $this->staleNotes($workspaceIds->all());
        $stats['pending'] = $stale->count();

        foreach ($stale->take(max(1, $limit)) as $note) {
            try {
                $stats['chunks'] += $this->index($note);
                $stats['indexed']++;
            } catch (EmbeddingFailed $failure) {
                // The provider is down or refusing: stop this run, retry next time.
                $stats['failed']++;
                $stats['error'] = $failure->getMessage();
                break;
            }
        }
        $stats['pending'] = max(0, $stats['pending'] - $stats['indexed']);

        return $stats;
    }

    /**
     * Deletes the index of a workspace (or all) so the next runs rebuild it from the notes.
     */
    public function forget(?Workspace $workspace = null): int
    {
        return NoteEmbedding::query()->when($workspace !== null, static fn ($query) => $query->where('workspace_id', $workspace->id))->delete();
    }

    /**
     * @param list<int> $workspaceIds
     * @return \Illuminate\Support\Collection<int, Note>
     */
    private function staleNotes(array $workspaceIds): \Illuminate\Support\Collection
    {
        $model = $this->provider->model();
        $current = NoteEmbedding::query()
            ->whereIn('workspace_id', $workspaceIds)
            ->where('chunk_index', 0)
            ->get(['note_id', 'content_hash', 'model'])
            ->keyBy('note_id');

        return Note::query()
            ->whereIn('workspace_id', $workspaceIds)
            ->orderByDesc('updated_at')
            ->orderBy('id')
            ->get(['id', 'workspace_id', 'content_hash', 'updated_at'])
            ->filter(static function (Note $note) use ($current, $model): bool {
                $row = $current->get($note->id);

                return $row === null || $row->content_hash !== (string) $note->content_hash || $row->model !== $model;
            })
            ->values();
    }

    private function index(Note $note): int
    {
        // Text is loaded per note, not for the whole stale list.
        $note = Note::query()->findOrFail($note->id, ['id', 'workspace_id', 'title', 'search_content', 'content_hash']);
        $chunks = array_slice($this->chunker->chunks((string) $note->title, (string) $note->search_content), 0, self::MAX_CHUNKS_PER_NOTE);
        $timeout = (int) config('jotter.semantic.index_timeout_seconds', 30);
        $batch = max(1, (int) config('jotter.semantic.batch_size', 32));

        $vectors = [];
        foreach (array_chunk($chunks, $batch) as $inputs) {
            array_push($vectors, ...$this->provider->embed($inputs, $timeout));
        }

        $model = $this->provider->model();
        $now = now();
        DB::transaction(function () use ($note, $chunks, $vectors, $model, $now): void {
            NoteEmbedding::query()->where('note_id', $note->id)->delete();
            $rows = [];
            foreach ($chunks as $index => $text) {
                $vector = VectorCodec::normalize($vectors[$index]);
                $rows[] = [
                    'workspace_id' => $note->workspace_id,
                    'note_id' => $note->id,
                    'chunk_index' => $index,
                    'content_hash' => (string) $note->content_hash,
                    'model' => $model,
                    'dimensions' => count($vector),
                    'chunk_text' => mb_substr($text, 0, 2000),
                    'vector' => VectorCodec::pack($vector),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            foreach (array_chunk($rows, 50) as $insert) {
                NoteEmbedding::query()->insert($insert);
            }
        });

        return count($chunks);
    }

    private function purgeOptedOut(): int
    {
        $optedOut = Workspace::query()->where('semantic_search', false)->pluck('id');
        if (! self::enabled()) {
            // Turned off globally: keep nothing that was derived from note text.
            return NoteEmbedding::query()->delete();
        }

        return $optedOut->isEmpty() ? 0 : NoteEmbedding::query()->whereIn('workspace_id', $optedOut)->delete();
    }
}
