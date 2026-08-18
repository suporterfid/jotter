<?php

namespace App\Domain\Export;

use App\Domain\Vault\MarkdownServerRenderer;
use App\Domain\Vault\VaultPathGuard;
use App\Models\Note;
use App\Models\Workspace;
use Dompdf\Dompdf;
use Dompdf\Options;

final class PdfDocumentRenderer
{
    public function __construct(
        private readonly MarkdownServerRenderer $markdownRenderer,
        private readonly PdfAssetResolver $assets,
        private readonly VaultPathGuard $paths = new VaultPathGuard,
    ) {}

    public function renderNote(Workspace $workspace, Note $note): string
    {
        return $this->renderDocument($workspace, [[
            'title' => $this->titleFor($note),
            'html' => $this->htmlFor($workspace, $note),
        ]]);
    }

    /**
     * @param iterable<Note> $notes
     */
    public function renderWorkspace(Workspace $workspace, iterable $notes): string
    {
        $documents = [];
        foreach ($notes as $note) {
            try {
                $documents[] = [
                    'title' => $this->titleFor($note),
                    'html' => $this->htmlFor($workspace, $note),
                ];
            } catch (\Throwable) {
                // A note removed between queueing and processing is omitted.
            }
        }

        if ($documents === []) {
            $documents[] = [
                'title' => $workspace->name ?: 'Workspace',
                'html' => '<p>No readable notes were available for this export.</p>',
            ];
        }

        return $this->renderDocument($workspace, $documents);
    }

    private function htmlFor(Workspace $workspace, Note $note): string
    {
        $path = $this->paths->resolve($workspace, $note->path, mustExist: true, mustBeMarkdown: true);
        $markdown = file_get_contents($path);
        if ($markdown === false) {
            throw new \RuntimeException("Unable to read note [{$note->path}].");
        }

        $html = $this->markdownRenderer->render($markdown, allowExternalEmbeds: false);

        return $this->assets->inlineLocalImages($html, $workspace, $note);
    }

    /**
     * @param list<array{title: string, html: string}> $documents
     */
    private function renderDocument(Workspace $workspace, array $documents): string
    {
        $css = $this->inlinePdfAssets($this->publishCss());
        $body = view('pdf.document', [
            'workspaceName' => $workspace->name ?: 'Workspace',
            'documents' => $documents,
            'css' => $css,
        ])->render();

        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($body, 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        return $dompdf->output();
    }

    private function publishCss(): string
    {
        $path = resource_path('views/publish/publish.css');

        return is_file($path) ? (string) file_get_contents($path) : '';
    }

    private function inlinePdfAssets(string $css): string
    {
        $fontDirectory = base_path('frontend/src/assets/fonts');
        foreach ([
            'inter-400.woff2',
            'inter-600.woff2',
            'inter-700.woff2',
            'source-serif-4-700.woff2',
            'ibm-plex-mono-400.woff2',
        ] as $font) {
            $path = $fontDirectory.'/'.$font;
            if (! is_file($path)) {
                continue;
            }

            $contents = file_get_contents($path);
            if ($contents === false) {
                continue;
            }

            $css = str_replace(
                "url('fonts/{$font}')",
                "url('data:font/woff2;base64,".base64_encode($contents)."')",
                $css,
            );
        }

        return $css;
    }

    private function titleFor(Note $note): string
    {
        return (string) ($note->title ?: pathinfo($note->path, PATHINFO_FILENAME));
    }
}
