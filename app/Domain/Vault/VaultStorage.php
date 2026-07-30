<?php

namespace App\Domain\Vault;

use App\Models\Note;
use App\Models\NoteLink;
use App\Models\Workspace;

/**
 * Path-safe read/write for workspace Markdown vaults. Disk files are source of truth;
 * MySQL is updated as a rebuildable projection on every successful write.
 */
final class VaultStorage
{
    public function __construct(
        private readonly VaultPathGuard $paths = new VaultPathGuard,
        private readonly NoteProjector $projector = new NoteProjector,
        private readonly NoteRevisions $revisions = new NoteRevisions,
    ) {}

    public function read(Workspace $workspace, string $relativePath): MarkdownDocument
    {
        return MarkdownDocument::parse(
            $this->readContents($workspace, $relativePath),
            $this->fallbackTitle($relativePath),
        );
    }

    public function readContents(Workspace $workspace, string $relativePath): string
    {
        $absolute = $this->paths->resolve($workspace, $relativePath, mustExist: true);

        $raw = file_get_contents($absolute);
        if ($raw === false) {
            throw new \RuntimeException("Unable to read vault note [{$relativePath}].");
        }

        return $raw;
    }

    public function write(Workspace $workspace, string $relativePath, string $contents): Note
    {
        $absolute = $this->paths->resolve($workspace, $relativePath, mustExist: false);
        $relative = $this->paths->toRelative($workspace, $absolute);

        $this->ensureParentDirectory($workspace, $absolute);

        // Re-resolve after parents exist so symlink escapes are caught before write.
        $absolute = $this->paths->resolve($workspace, $relative, mustExist: false);

        $written = file_put_contents($absolute, $contents, LOCK_EX);
        if ($written === false) {
            throw new \RuntimeException("Unable to write vault note [{$relative}].");
        }

        $document = MarkdownDocument::parse($contents, $this->fallbackTitle($relative));

        $note = $this->projector->project($workspace, $relative, $document);
        $this->revisions->recordRevision($note, $contents);

        return $note;
    }

    /**
     * @param  array<string, mixed>  $frontmatter
     */
    public function writeDocument(Workspace $workspace, string $relativePath, array $frontmatter, string $body): Note
    {
        return $this->write(
            $workspace,
            $relativePath,
            MarkdownDocument::compose($frontmatter, $body),
        );
    }

    public function exists(Workspace $workspace, string $relativePath): bool
    {
        try {
            $absolute = $this->paths->resolve($workspace, $relativePath, mustExist: false);
        } catch (\Throwable) {
            return false;
        }

        return is_file($absolute);
    }

    public function delete(Workspace $workspace, string $relativePath): void
    {
        $absolute = $this->paths->resolve($workspace, $relativePath, mustExist: true);
        $relative = $this->paths->toRelative($workspace, $absolute);

        if (! unlink($absolute)) {
            throw new \RuntimeException("Unable to delete vault note [{$relative}].");
        }

        Note::query()
            ->where('workspace_id', $workspace->id)
            ->where('path', $relative)
            ->delete();
    }

    public function move(Workspace $workspace, string $oldPath, string $newPath): Note
    {
        $oldAbsolute = $this->paths->resolve($workspace, $oldPath, mustExist: true);
        $newAbsolute = $this->paths->resolve($workspace, $newPath, mustExist: false);
        $newRelative = $this->paths->toRelative($workspace, $newAbsolute);

        $this->ensureParentDirectory($workspace, $newAbsolute);

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

        if (! rename($oldAbsolute, $newAbsolute)) {
            throw new \RuntimeException("Unable to move vault note from [{$oldPath}] to [{$newPath}].");
        }

        $contents = file_get_contents($newAbsolute) ?: '';
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

    private function ensureParentDirectory(Workspace $workspace, string $absolute): void
    {
        $root = $this->paths->ensureVaultRoot($workspace);
        $parent = dirname($absolute);

        $rootNormalized = rtrim(str_replace('\\', '/', $root), '/');
        $parentNormalized = rtrim(str_replace('\\', '/', $parent), '/');

        if ($parentNormalized !== $rootNormalized && ! str_starts_with($parentNormalized, $rootNormalized.'/')) {
            throw new \RuntimeException('Refusing to create directories outside the vault root.');
        }

        if (is_dir($parent)) {
            $realParent = realpath($parent);
            if ($realParent === false) {
                throw new \RuntimeException('Vault parent directory could not be canonicalized.');
            }

            $realNormalized = str_replace('\\', '/', $realParent);
            if ($realNormalized !== $rootNormalized && ! str_starts_with($realNormalized, $rootNormalized.'/')) {
                throw new \RuntimeException('Refusing to use a vault parent outside the workspace root.');
            }

            return;
        }

        $relativeParent = trim(substr($parentNormalized, strlen($rootNormalized)), '/');
        if ($relativeParent !== '') {
            foreach (explode('/', $relativeParent) as $segment) {
                if ($segment === '' || $segment === '.' || $segment === '..' || str_contains($segment, ':')) {
                    throw new \RuntimeException('Refusing to create unsafe vault directories.');
                }
            }
        }

        if (! mkdir($parent, 0755, true) && ! is_dir($parent)) {
            throw new \RuntimeException("Unable to create vault directory for [{$absolute}].");
        }

        $realParent = realpath($parent);
        if ($realParent === false) {
            throw new \RuntimeException('Vault parent directory could not be canonicalized.');
        }

        $realNormalized = str_replace('\\', '/', $realParent);
        if ($realNormalized !== $rootNormalized && ! str_starts_with($realNormalized, $rootNormalized.'/')) {
            throw new \RuntimeException('Refusing to use a vault parent outside the workspace root.');
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
