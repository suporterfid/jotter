<?php

namespace App\Domain\Sharing;

use App\Domain\Vault\Exceptions\VaultNoteNotFound;
use App\Domain\Vault\MarkdownServerRenderer;
use App\Domain\Vault\VaultPathGuard;
use App\Models\NoteShare;

final class SharedNoteRenderer
{
    public function __construct(
        private readonly MarkdownServerRenderer $renderer,
        private readonly SharedAssetResolver $assets,
        private readonly VaultPathGuard $paths,
    ) {}

    /** @return array{title: string, html: string, locale: string, direction: string} */
    public function render(NoteShare $share, string $plainToken): array
    {
        $share->loadMissing('note.workspace');
        $note = $share->note;
        if ($note === null) {
            throw new VaultNoteNotFound('note');
        }

        try {
            $path = $this->paths->resolve($note->workspace, $note->path, mustExist: true, mustBeMarkdown: true);
            $markdown = file_get_contents($path);
        } catch (VaultNoteNotFound) {
            throw new VaultNoteNotFound($note->path);
        }

        if ($markdown === false) {
            throw new VaultNoteNotFound($note->path);
        }

        $html = $this->renderer->render($markdown, allowExternalEmbeds: false, wikilinkMode: 'plain');
        $html = $this->assets->rewriteAttachmentUrls($html, $note, $plainToken);
        $locale = (string) config('app.locale', 'en');

        return [
            'title' => $note->title,
            'html' => $html,
            'locale' => $locale,
            'direction' => $this->direction($locale),
        ];
    }

    private function direction(string $locale): string
    {
        $language = strtolower(strtok(str_replace('_', '-', $locale), '-'));

        return in_array($language, ['ar', 'he'], true) ? 'rtl' : 'ltr';
    }
}
