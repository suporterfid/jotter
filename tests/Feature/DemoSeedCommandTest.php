<?php

namespace Tests\Feature;

use App\Models\Note;
use App\Models\NoteLink;
use App\Models\Tenant;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class DemoSeedCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $vault;

    protected function setUp(): void
    {
        parent::setUp();
        $this->vault = storage_path('framework/testing/demo-'.uniqid());
        File::ensureDirectoryExists($this->vault);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->vault);
        parent::tearDown();
    }

    public function test_demo_seed_writes_interlinked_portuguese_notes(): void
    {
        $tenant = Tenant::create(['slug' => 'demo', 'name' => 'Demo']);
        $workspace = Workspace::create(['tenant_id' => $tenant->id, 'slug' => 'showcase', 'name' => 'Showcase', 'vault_path' => $this->vault]);

        $this->artisan('demo:seed showcase')->expectsOutputToContain('25 note(s) written, 0 skipped')->assertSuccessful();

        $this->assertSame(25, Note::query()->where('workspace_id', $workspace->id)->count());
        $this->assertFileExists($this->vault.'/Início.md');
        $this->assertFileExists($this->vault.'/adr/ADR-004 Ferramentas MCP somente leitura.md');

        $noteIds = Note::query()->where('workspace_id', $workspace->id)->pluck('id');
        $resolved = NoteLink::query()->whereIn('source_note_id', $noteIds)->whereNotNull('target_note_id')->count();
        $unresolved = NoteLink::query()->whereIn('source_note_id', $noteIds)->whereNull('target_note_id')->count();
        $this->assertGreaterThan(40, $resolved, 'demo notes are densely interlinked');
        $this->assertSame(0, $unresolved, 'unresolved wikilinks: '.NoteLink::query()->whereIn('source_note_id', $noteIds)->whereNull('target_note_id')->pluck('target_ref')->implode(', '));

        // Idempotent without --overwrite; --overwrite rewrites.
        $this->artisan('demo:seed showcase')->expectsOutputToContain('0 note(s) written, 25 skipped')->assertSuccessful();
        $this->artisan('demo:seed showcase --overwrite')->expectsOutputToContain('25 note(s) written, 0 skipped')->assertSuccessful();
    }

    public function test_demo_seed_requires_an_unambiguous_workspace(): void
    {
        $this->artisan('demo:seed missing')->assertFailed();

        $a = Tenant::create(['slug' => 'a', 'name' => 'A']);
        $b = Tenant::create(['slug' => 'b', 'name' => 'B']);
        Workspace::create(['tenant_id' => $a->id, 'slug' => 'docs', 'name' => 'Docs', 'vault_path' => $this->vault.'/a']);
        Workspace::create(['tenant_id' => $b->id, 'slug' => 'docs', 'name' => 'Docs', 'vault_path' => $this->vault.'/b']);

        $this->artisan('demo:seed docs')->expectsOutputToContain('pass --tenant=<slug>')->assertFailed();
        $this->artisan('demo:seed docs --tenant=b')->assertSuccessful();
        $this->assertFileExists($this->vault.'/b/Início.md');
    }
}
