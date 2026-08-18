<?php

namespace Tests\Unit;

use App\Domain\Export\PdfAssetResolver;
use App\Models\Note;
use App\Models\Workspace;
use Tests\TestCase;

final class PdfAssetResolverTest extends TestCase
{
    private string $vaultPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vaultPath = storage_path('app/vaults/pdf-assets-'.bin2hex(random_bytes(4)));
        mkdir($this->vaultPath.'/_resources', 0755, true);
        file_put_contents(
            $this->vaultPath.'/_resources/diagram.png',
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true),
        );
    }

    protected function tearDown(): void
    {
        if (is_dir($this->vaultPath)) {
            $this->removeDirectory($this->vaultPath);
        }

        parent::tearDown();
    }

    public function test_local_images_are_inlined_as_data_uris(): void
    {
        $workspace = new Workspace(['vault_path' => $this->vaultPath, 'tenant_id' => 1]);
        $note = new Note(['path' => 'source.md']);

        $html = app(PdfAssetResolver::class)->inlineLocalImages(
            '<p><img src="_resources/diagram.png" alt="Diagram"></p>',
            $workspace,
            $note,
        );

        $this->assertStringContainsString('src="data:image/png;base64,', $html);
        $this->assertStringContainsString('alt="Diagram"', $html);
    }

    public function test_remote_and_unsafe_image_sources_are_not_fetched_or_preserved(): void
    {
        $workspace = new Workspace(['vault_path' => $this->vaultPath, 'tenant_id' => 1]);
        $note = new Note(['path' => 'source.md']);

        $html = app(PdfAssetResolver::class)->inlineLocalImages(
            '<img src="https://example.com/remote.png"><img src="file:///tmp/local.png"><img src="javascript:alert(1)">',
            $workspace,
            $note,
        );

        $this->assertStringNotContainsString('https://example.com/remote.png', $html);
        $this->assertStringNotContainsString('file:///tmp/local.png', $html);
        $this->assertStringNotContainsString('javascript:alert(1)', $html);
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
