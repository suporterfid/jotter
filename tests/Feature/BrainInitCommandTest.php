<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Note;
use App\Models\Tenant;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use ZipArchive;

final class BrainInitCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $vaultRoot;
    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->vaultRoot = sys_get_temp_dir().'/jotter-brain-init-'.uniqid('', true);
        File::ensureDirectoryExists($this->vaultRoot);
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);
        $this->workspace = Workspace::create(['tenant_id' => $tenant->id, 'slug' => 'brain', 'name' => 'Brain', 'vault_path' => $this->vaultRoot]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->vaultRoot);
        parent::tearDown();
    }

    public function test_it_creates_the_brain_layout_and_is_idempotent(): void
    {
        $this->assertSame(0, Artisan::call('brain:init', ['workspace' => 'brain', '--json' => true]));
        $first = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertContains('_brain/README.md', $first['written']);
        $this->assertContains('_brain/instructions.md', $first['written']);
        $this->assertContains('projetos/exemplo/estado.md', $first['written']);
        $this->assertContains('inbox/README.md', $first['written']);
        $this->assertSame([], $first['skipped']);
        $this->assertFileExists($this->vaultRoot.'/diario/README.md');
        $this->assertSame('ativo', Note::query()->where('path', 'projetos/exemplo/estado.md')->sole()->frontmatter['status']);

        // A human customizes the router; running again keeps it.
        file_put_contents($this->vaultRoot.'/_brain/README.md', "# Meu roteador\n");
        $this->assertSame(0, Artisan::call('brain:init', ['workspace' => (string) $this->workspace->id, '--json' => true]));
        $second = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame([], $second['written']);
        $this->assertCount(count($first['written']), $second['skipped']);
        $this->assertSame("# Meu roteador\n", file_get_contents($this->vaultRoot.'/_brain/README.md'));
        $this->assertSame(2, AuditLog::query()->where('event', 'brain.initialized')->count());
    }

    public function test_dry_run_writes_nothing_and_english_locale_keeps_folder_names(): void
    {
        $this->assertSame(0, Artisan::call('brain:init', ['workspace' => 'brain', '--locale' => 'en', '--dry-run' => true, '--json' => true]));
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertTrue($report['dry_run']);
        $this->assertContains('diario/README.md', $report['written']);
        $this->assertFileDoesNotExist($this->vaultRoot.'/_brain/README.md');
        $this->assertSame(0, AuditLog::query()->where('event', 'brain.initialized')->count());

        $this->artisan('brain:init', ['workspace' => 'ghost'])->assertFailed();
    }

    public function test_templates_pack_can_build_the_brain_zip(): void
    {
        $zipPath = $this->vaultRoot.'/../brain-pack-'.uniqid().'.zip';
        $this->artisan('templates:pack', ['--pack' => 'brain', '--locale' => 'pt-BR', '--to' => $zipPath])
            ->expectsOutputToContain('Brain pack (pt-BR')
            ->assertSuccessful();

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($zipPath) === true);
        $this->assertNotFalse($zip->locateName('_brain/instructions.md'));
        $this->assertNotFalse($zip->locateName('projetos/exemplo/estado.md'));
        $zip->close();
        @unlink($zipPath);

        $this->artisan('templates:pack', ['--pack' => 'nope'])->assertFailed();
    }
}
