<?php

namespace Tests\Feature;

use App\Domain\Provisioning\TemplatePack;
use App\Models\Note;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class TemplatePackTest extends TestCase
{
    use RefreshDatabase;

    private string $scratch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scratch = storage_path('framework/testing/templates-'.uniqid());
        File::ensureDirectoryExists($this->scratch.'/vault');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->scratch);

        parent::tearDown();
    }

    public function test_pack_ships_the_five_templates_in_both_locales(): void
    {
        $pack = app(TemplatePack::class);

        foreach (['en', 'pt-BR'] as $locale) {
            $files = $pack->files($locale);
            $this->assertSame(
                ['_templates/adr.md', '_templates/daily.md', '_templates/meeting-notes.md', '_templates/prd.md', '_templates/runbook.md'],
                array_keys($files),
                $locale,
            );
            foreach ($files as $contents) {
                $this->assertStringContainsString('{{date}}', $contents);
            }
        }
        $this->assertStringContainsString('# Runbook:', $pack->files('en')['_templates/runbook.md']);
        $this->assertStringContainsString('## Objetivo', $pack->files('pt-BR')['_templates/runbook.md']);
        $this->assertSame('en', TemplatePack::normalizeLocale('fr'));
    }

    public function test_pack_zip_is_importable_through_the_workspace_import_endpoint(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);
        $workspace = Workspace::create(['tenant_id' => $tenant->id, 'slug' => 'docs', 'name' => 'Docs', 'vault_path' => $this->scratch.'/vault']);

        $this->artisan('templates:pack', ['--locale' => 'pt-BR', '--to' => $this->scratch.'/templates.zip'])
            ->expectsOutputToContain('Template pack (pt-BR, 5 files)')
            ->assertSuccessful();

        $response = $this->actingAs($admin)->post("/api/workspaces/{$workspace->id}/import", [
            'archive' => new UploadedFile($this->scratch.'/templates.zip', 'templates.zip', 'application/zip', null, true),
        ]);

        $response->assertOk()->assertJsonPath('extracted_count', 5);
        $this->assertFileExists($this->scratch.'/vault/_templates/prd.md');
        $this->assertSame(5, Note::query()->where('workspace_id', $workspace->id)->where('path', 'like', '_templates/%')->count());
    }

    public function test_install_does_not_overwrite_customized_templates(): void
    {
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);
        $workspace = Workspace::create(['tenant_id' => $tenant->id, 'slug' => 'docs', 'name' => 'Docs', 'vault_path' => $this->scratch.'/vault']);
        $pack = app(TemplatePack::class);

        $this->assertCount(5, $pack->install($workspace, 'en'));
        file_put_contents($this->scratch.'/vault/_templates/daily.md', "# Mine\n");

        $this->assertSame([], $pack->install($workspace, 'en'));
        $this->assertSame("# Mine\n", file_get_contents($this->scratch.'/vault/_templates/daily.md'));
    }
}
