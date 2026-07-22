<?php

namespace App\Domain\Vault;

use App\Models\Note;
use App\Models\Tag;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

/**
 * Maintains the rebuildable MySQL projection for a vault Markdown document.
 */
final class NoteProjector
{
    public function project(Workspace $workspace, string $relativePath, MarkdownDocument $document): Note
    {
        return DB::transaction(function () use ($workspace, $relativePath, $document): Note {
            /** @var Note $note */
            $note = Note::query()->updateOrCreate(
                [
                    'workspace_id' => $workspace->id,
                    'path' => $relativePath,
                ],
                [
                    'title' => $document->title,
                    'frontmatter' => $document->frontmatter === [] ? null : $document->frontmatter,
                    'content_hash' => $document->contentHash,
                    'search_content' => $document->searchContent === '' ? null : $document->searchContent,
                ],
            );

            $this->syncTags($workspace, $note, $document->tags);

            // TODO(spec: PR3): extract [[wikilinks]] from the body and project note_links / backlinks.
            // Reindex and writes intentionally leave note_links untouched in PR2.

            return $note->refresh();
        });
    }

    /**
     * @param  list<string>  $tagNames
     */
    private function syncTags(Workspace $workspace, Note $note, array $tagNames): void
    {
        $tagIds = [];

        foreach ($tagNames as $name) {
            $tag = Tag::query()->firstOrCreate(
                [
                    'workspace_id' => $workspace->id,
                    'name' => $name,
                ],
            );
            $tagIds[] = $tag->id;
        }

        $note->tags()->sync($tagIds);
    }
}
