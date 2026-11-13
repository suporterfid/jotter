<?php

namespace App\Domain\Vault;

use App\Domain\Vault\Store\VaultStore;
use App\Domain\Vault\Store\VaultStoreResolver;
use App\Models\Note;
use App\Models\NoteLink;
use App\Models\Workspace;

/**
 * Path-safe read/write for workspace Markdown vaults. Vault files (in the
 * workspace's VaultStore: local folder by default) are the source of truth;
 * MySQL is updated as a rebuildable projection on every successful write.
 */
final class VaultStorage
{
    public function __construct(
        private readonly VaultPathGuard $paths = new VaultPathGuard,
        private readonly NoteProjector $projector = new NoteProjector,
        private readonly NoteRevisions $revisions = new NoteRevisions,
        private readonly ?VaultStoreResolver $stores = null,
    ) {}

    public function store(Workspace $workspace): VaultStore
    {
        return ($this->stores ?? app(VaultStoreResolver::class))->for($workspace);
    }

    public function read(Workspace $workspace, string $relativePath): MarkdownDocument
    {
        return MarkdownDocument::parse(
            $this->readContents($workspace, $relativePath),
            $this->fallbackTitle($relativePath),
        );
    }

    public function readContents(Workspace $workspace, string $relativePath): string
    {
        $relative = $this->paths->normalize($workspace, $relativePath);

        return $this->store($workspace)->read($relative);
    }

    public function write(Workspace $workspace, string $relativePath, string $contents, ?string $actorId = null): Note
    {
        $relative = $this->paths->normalize($workspace, $relativePath);
        $store = $this->store($workspace);

        // Storage quota (#390): only growth is refused.
        $delta = strlen($contents) - ($store->stat($relative)?->size ?? 0);
        $quota = app(\App\Domain\Quota\WorkspaceQuota::class);
        $quota->assertCanGrow($workspace, $delta);

        $store->write($relative, $contents);
        $quota->recordDelta($workspace, $delta);

        $document = MarkdownDocument::parse($contents, $this->fallbackTitle($relative));

        $note = $this->projector->project($workspace, $relative, $document);
        $this->revisions->recordRevision($note, $contents, $actorId);

        return $note;
    }

    /**
     * @param  array<string, mixed>  $frontmatter
     */
    public function writeDocument(Workspace $workspace, string $relativePath, array $frontmatter, string $body, ?string $actorId = null): Note
    {
        return $this->write(
            $workspace,
            $relativePath,
            MarkdownDocument::compose($frontmatter, $body),
            $actorId,
        );
    }

    public function exists(Workspace $workspace, string $relativePath): bool
    {
        try {
            $relative = $this->paths->normalize($workspace, $relativePath);

            return $this->store($workspace)->exists($relative);
        } catch (\Throwable) {
            return false;
        }
    }

    public function moveFile(Workspace $workspace, string $oldPath, string $newPath): void
    {
        $from = $this->paths->normalize($workspace, $oldPath);
        $to = $this->paths->normalize($workspace, $newPath);

        $this->store($workspace)->move($from, $to);
    }

    public function delete(Workspace $workspace, string $relativePath): void
    {
        $relative = $this->paths->normalize($workspace, $relativePath);

        $this->store($workspace)->delete($relative);

        Note::query()
            ->where('workspace_id', $workspace->id)
            ->where('path', $relative)
            ->delete();
    }

    public function move(Workspace $workspace, string $oldPath, string $newPath): Note
    {
        $store = $this->store($workspace);
        $oldRelative = $this->paths->normalize($workspace, $oldPath);
        $newRelative = $this->paths->normalize($workspace, $newPath);

        if (! $store->exists($oldRelative)) {
            throw new Exceptions\VaultNoteNotFound($oldPath);
        }

        $note = Note::query()
            ->where('workspace_id', $workspace->id)
            ->where('path', $oldPath)
            ->first();

        // Capture who links to this note, and what old keys their links use to
        // reach it, before the rename changes path/title resolution.
        $oldKeys = $note ? $this->wikilinkKeys($oldPath, (string) $note->title) : $this->wikilinkKeys($oldPath, null);
        $referencingNoteIds = $note
            ? NoteLink::query()->where('target_note_id', $note->id)->where('type', 'wikilink')
                ->pluck('source_note_id')->unique()->all()
            : [];

        $store->move($oldRelative, $newRelative);

        $contents = $store->read($newRelative);
        $document = MarkdownDocument::parse($contents, $this->fallbackTitle($newRelative));

        if ($note) {
            $note->delete();
        }

        $movedNote = $this->projector->project($workspace, $newRelative, $document);

        if ($referencingNoteIds !== []) {
            // Obsidian wikilinks address notes by filename, not by H1/front-matter
            // title, so rewrite to the new file's basename -- consistent with
            // resolveWorkspaceLinks() matching against note path first.
            $newKey = $this->fallbackTitle($newRelative);
            $this->rewriteInboundWikilinks($workspace, $referencingNoteIds, $oldKeys, $newKey, $movedNote->id);
        }

        return $movedNote;
    }

    /**
     * @return list<string>
     */
    private function wikilinkKeys(string $relativePath, ?string $title): array
    {
        $path = trim(str_replace('\\', '/', $relativePath), '/');
        $keys = [$path];
        if (str_ends_with(strtolower($path), '.md')) {
            $keys[] = substr($path, 0, -3);
        }

        $title = trim((string) $title);
        if ($title !== '') {
            $keys[] = $title;
        }

        return array_values(array_unique($keys));
    }

    /**
     * Rewrites `[[oldKey]]` / `[[oldKey|label]]` / `[[oldKey#fragment]]` wikilinks
     * to the moved note's new title, in every referencing note's on-disk content.
     * Disk is the source of truth (spec §1); the note index is rebuilt as a
     * side effect of the write, same as any other edit.
     *
     * @param  list<int>  $referencingNoteIds
     * @param  list<string>  $oldKeys
     */
    private function rewriteInboundWikilinks(
        Workspace $workspace,
        array $referencingNoteIds,
        array $oldKeys,
        string $newTitle,
        int $movedNoteId,
    ): void {
        if ($oldKeys === []) {
            return;
        }

        $alternation = implode('|', array_map(
            static fn (string $key): string => preg_quote($key, '/'),
            $oldKeys,
        ));
        $pattern = '/\[\[('.$alternation.')((?:#[^\]|]*)?(?:\|[^\]]*)?)\]\]/iu';

        $referencingNotes = Note::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('id', $referencingNoteIds)
            ->where('id', '!=', $movedNoteId)
            ->get(['id', 'path']);

        foreach ($referencingNotes as $referencingNote) {
            $contents = $this->readContents($workspace, (string) $referencingNote->path);

            $rewritten = preg_replace_callback(
                $pattern,
                static fn (array $matches): string => sprintf('[[%s%s]]', $newTitle, $matches[2]),
                $contents,
            );

            if ($rewritten !== null && $rewritten !== $contents) {
                $this->write($workspace, (string) $referencingNote->path, $rewritten);
            }
        }
    }

    private function fallbackTitle(string $relativePath): string
    {
        $base = basename(str_replace('\\', '/', $relativePath));
        if (str_ends_with(strtolower($base), '.md')) {
            $base = substr($base, 0, -3);
        }

        return $base === '' ? 'Untitled' : $base;
    }
}
