<?php

namespace Tests\Feature;

use App\Domain\Vault\ImportPathNormalizer;
use App\Domain\Vault\ImportSource;
use App\Models\Note;
use App\Models\NoteLink;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use ZipArchive;

/**
 * Import of real-world export layouts: an Obsidian vault folder and a Notion
 * "Markdown & CSV" export with 32-hex page ids and URL-encoded links.
 */
final class ImportSourcesTest extends TestCase
{
    use RefreshDatabase;

    private string $scratch;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scratch = storage_path('framework/testing/import-'.uniqid());
        File::ensureDirectoryExists($this->scratch.'/vault');
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);
        $this->workspace = Workspace::create(['tenant_id' => $tenant->id, 'slug' => 'docs', 'name' => 'Docs', 'vault_path' => $this->scratch.'/vault']);
        $this->actingAs(User::factory()->create(['is_admin' => true]));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->scratch);

        parent::tearDown();
    }

    /**
     * @param  array<string, string>  $entries
     */
    private function zip(string $name, array $entries): UploadedFile
    {
        $path = $this->scratch.'/'.$name;
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($entries as $entry => $contents) {
            $zip->addFromString($entry, $contents);
        }
        $zip->close();

        return new UploadedFile($path, $name, 'application/zip', null, true);
    }

    public function test_obsidian_export_strips_the_vault_folder_and_skips_obsidian_metadata(): void
    {
        $archive = $this->zip('obsidian.zip', [
            'My Vault/.obsidian/app.json' => '{"livePreview":true}',
            'My Vault/.obsidian/workspace.json' => '{}',
            'My Vault/.trash/old.md' => '# Old',
            'My Vault/Index.md' => "# Index\n\nSee [[Projects/Aurora]] and ![[attachments/logo.png]].\n",
            'My Vault/Projects/Aurora.md' => "---\ntags: [project]\n---\n# Aurora\n\nBack to [[Index]].\n",
            'My Vault/attachments/logo.png' => 'png-bytes',
        ]);

        $response = $this->post("/api/workspaces/{$this->workspace->id}/import", ['archive' => $archive, 'source' => 'obsidian'])->assertOk();

        $this->assertSame(3, $response->json('extracted_count'));
        $this->assertFileExists($this->scratch.'/vault/Index.md');
        $this->assertFileExists($this->scratch.'/vault/Projects/Aurora.md');
        $this->assertFileExists($this->scratch.'/vault/attachments/logo.png');
        $this->assertDirectoryDoesNotExist($this->scratch.'/vault/My Vault');
        $this->assertDirectoryDoesNotExist($this->scratch.'/vault/.obsidian');
        $this->assertFileDoesNotExist($this->scratch.'/vault/.trash/old.md');

        $index = Note::query()->where('workspace_id', $this->workspace->id)->where('path', 'Index.md')->firstOrFail();
        $aurora = Note::query()->where('workspace_id', $this->workspace->id)->where('path', 'Projects/Aurora.md')->firstOrFail();
        $this->assertTrue(NoteLink::query()->where('source_note_id', $aurora->id)->where('target_note_id', $index->id)->exists(), 'wikilinks resolve after import');
    }

    public function test_notion_export_strips_page_ids_and_rewrites_links_to_wikilinks(): void
    {
        $archive = $this->zip('notion.zip', [
            'Export-3f2a1c9e-1111-2222-3333-444455556666/Engineering Wiki 2f9c0b1a3d4e5f60718293a4b5c6d7e8.md' => "# Engineering Wiki\n\nStart with [Onboarding](Engineering%20Wiki%202f9c0b1a3d4e5f60718293a4b5c6d7e8/Onboarding%20abcdefabcdefabcdefabcdefabcdefab.md) and the [logo](Engineering%20Wiki%202f9c0b1a3d4e5f60718293a4b5c6d7e8/logo.png).\n\nExternal: [MCP](https://modelcontextprotocol.io/).\n",
            'Export-3f2a1c9e-1111-2222-3333-444455556666/Engineering Wiki 2f9c0b1a3d4e5f60718293a4b5c6d7e8/Onboarding abcdefabcdefabcdefabcdefabcdefab.md' => "# Onboarding\n\nBack to [wiki](../Engineering%20Wiki%202f9c0b1a3d4e5f60718293a4b5c6d7e8.md#top).\n",
            'Export-3f2a1c9e-1111-2222-3333-444455556666/Engineering Wiki 2f9c0b1a3d4e5f60718293a4b5c6d7e8/logo.png' => 'png-bytes',
            'Export-3f2a1c9e-1111-2222-3333-444455556666/Team Directory 0123456789abcdef0123456789abcdef.csv' => "Name,Role\nAna,PM\n",
        ]);

        $response = $this->post("/api/workspaces/{$this->workspace->id}/import", ['archive' => $archive, 'source' => 'notion'])->assertOk();

        $this->assertSame(3, $response->json('extracted_count'));
        $this->assertFileExists($this->scratch.'/vault/Engineering Wiki.md');
        $this->assertFileExists($this->scratch.'/vault/Engineering Wiki/Onboarding.md');
        $this->assertFileExists($this->scratch.'/vault/Engineering Wiki/logo.png');
        $this->assertStringContainsString('Disallowed file type: Team Directory.csv', implode("\n", $response->json('errors')));

        $wiki = (string) file_get_contents($this->scratch.'/vault/Engineering Wiki.md');
        $this->assertStringContainsString('[Onboarding]([[Engineering Wiki/Onboarding]])', $wiki);
        $this->assertStringContainsString('[logo](Engineering%20Wiki/logo.png)', $wiki);
        $this->assertStringContainsString('[MCP](https://modelcontextprotocol.io/)', $wiki);
        $this->assertStringNotContainsString('2f9c0b1a3d4e5f60718293a4b5c6d7e8', $wiki);

        $onboarding = (string) file_get_contents($this->scratch.'/vault/Engineering Wiki/Onboarding.md');
        $this->assertStringContainsString('[wiki]([[../Engineering Wiki]])', $onboarding);
    }

    public function test_generic_source_keeps_paths_untouched(): void
    {
        $archive = $this->zip('generic.zip', ['Root/Note abcdefabcdefabcdefabcdefabcdefab.md' => '# Keep']);

        $this->post("/api/workspaces/{$this->workspace->id}/import", ['archive' => $archive])->assertOk()->assertJsonPath('extracted_count', 1);

        $this->assertFileExists($this->scratch.'/vault/Root/Note abcdefabcdefabcdefabcdefabcdefab.md');
        $this->withHeaders(['Accept' => 'application/json'])
            ->post("/api/workspaces/{$this->workspace->id}/import", ['archive' => $this->zip('bad.zip', ['a.md' => '#']), 'source' => 'evernote'])
            ->assertStatus(422);
    }

    public function test_normalizer_edge_cases(): void
    {
        $normalizer = new ImportPathNormalizer;

        $this->assertNull($normalizer->detectRootDirectory(['a.md', 'dir/b.md']));
        $this->assertSame('Vault/', $normalizer->detectRootDirectory(['Vault/a.md', 'Vault/sub/b.md']));
        $this->assertSame('Page.md', $normalizer->normalize(ImportSource::NOTION, 'Page 0123456789abcdef0123456789abcdef.md', null));
        $this->assertSame('Page with id inside.md', $normalizer->normalize(ImportSource::NOTION, 'Page with id inside.md', null));
        $this->assertSame('](https://x.y/z)', $normalizer->rewriteNotionMarkdown('](https://x.y/z)'));
    }
}
