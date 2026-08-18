<?php

namespace Tests\Feature;

use App\Domain\Export\PdfDocumentRenderer;
use App\Models\Note;
use App\Models\Workspace;
use Tests\TestCase;

final class PdfDocumentRendererTest extends TestCase
{
    private string $vaultPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vaultPath = storage_path('app/vaults/pdf-renderer-'.bin2hex(random_bytes(4)));
        mkdir($this->vaultPath.'/_resources', 0755, true);
        file_put_contents($this->vaultPath.'/guide.md', implode("\n", [
            '# Guide',
            '',
            'A [[Target Note]] reference.',
            '',
            '> [!NOTE] A callout',
            '',
            '| A | B |',
            '|---|---|',
            '| 1 | 2 |',
            '',
            '```php',
            'echo "safe";',
            '```',
        ]));
        file_put_contents($this->vaultPath.'/second.md', '# Second');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->vaultPath)) {
            $this->removeDirectory($this->vaultPath);
        }

        parent::tearDown();
    }

    public function test_render_note_returns_a_pdf_document(): void
    {
        $workspace = new Workspace(['vault_path' => $this->vaultPath, 'name' => 'PDF Workspace', 'tenant_id' => 1]);
        $note = new Note(['path' => 'guide.md', 'title' => 'Guide']);

        $pdf = app(PdfDocumentRenderer::class)->renderNote($workspace, $note);

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertGreaterThan(1000, strlen($pdf));
    }

    public function test_render_workspace_returns_a_pdf_document_for_multiple_notes(): void
    {
        $workspace = new Workspace(['vault_path' => $this->vaultPath, 'name' => 'PDF Workspace', 'tenant_id' => 1]);
        $notes = collect([
            new Note(['path' => 'guide.md', 'title' => 'Guide']),
            new Note(['path' => 'second.md', 'title' => 'Second']),
        ]);

        $pdf = app(PdfDocumentRenderer::class)->renderWorkspace($workspace, $notes);

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertGreaterThan(1200, strlen($pdf));
    }

    private function removeDirectory(string $directory): void
    {
        foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $entry) {
            $path = $directory.DIRECTORY_SEPARATOR.$entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($directory);
    }
}
