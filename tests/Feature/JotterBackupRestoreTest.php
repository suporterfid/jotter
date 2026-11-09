<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\Note;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use ZipArchive;

/**
 * #390 item 3: `jotter:backup` and `jotter:restore` round-trip the database and
 * the vault files. DatabaseMigrations (not RefreshDatabase): restore truncates
 * tables, which commits any open transaction on MySQL.
 */
final class JotterBackupRestoreTest extends TestCase
{
    use DatabaseMigrations;

    private string $dir;
    private string $vault;
    private Workspace $workspace;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('app/private/backups-test-'.uniqid());
        config(['jotter.backup.path' => $this->dir]);

        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);
        $this->vault = storage_path('app/vaults/backup_'.uniqid());
        File::ensureDirectoryExists($this->vault.'/projetos/x');
        File::put($this->vault.'/projetos/x/estado.md', "# Estado\n\nprimeiro\n");
        File::put($this->vault.'/logo.png', "\x89PNG\r\n\x1a\n".random_bytes(64));
        File::ensureDirectoryExists($this->vault.'/vazio');
        $this->workspace = Workspace::create(['tenant_id' => $tenant->id, 'slug' => 'brain', 'name' => 'Brain', 'vault_path' => $this->vault]);
        $this->user = User::factory()->create(['email' => 'joe@acme.test', 'name' => 'Joé Ünicode']);
        Membership::create(['tenant_id' => $tenant->id, 'workspace_id' => $this->workspace->id, 'subject_id' => (string) $this->user->id, 'role' => 'editor']);
        Note::create(['workspace_id' => $this->workspace->id, 'path' => 'projetos/x/estado.md', 'title' => 'Estado', 'content_hash' => str_repeat('a', 64)]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        File::deleteDirectory($this->vault);
        File::deleteDirectory($this->vault.'-moved');
        parent::tearDown();
    }

    public function test_backup_then_restore_brings_back_rows_and_files(): void
    {
        $png = (string) file_get_contents($this->vault.'/logo.png');
        $this->assertSame(0, Artisan::call('jotter:backup', ['--json' => true]));
        $summary = json_decode(Artisan::output(), true);
        $this->assertFileExists($summary['path']);
        $this->assertSame(2, $summary['files']);

        $zip = new ZipArchive;
        $zip->open($summary['path']);
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        $this->assertSame('jotter-backup/1', $manifest['format']);
        $this->assertArrayHasKey('users', $manifest['tables']);
        $this->assertArrayNotHasKey('sessions', $manifest['tables'], 'ephemeral tables are left out');
        $this->assertArrayNotHasKey('note_embeddings', $manifest['tables'], 'rebuildable projections are left out');
        $this->assertNotFalse($zip->locateName('vaults/'.$this->workspace->id.'/projetos/x/estado.md'));
        $zip->close();

        // Disaster: rows and files are gone or changed.
        Membership::query()->delete();
        $this->user->delete();
        Note::query()->update(['title' => 'mudou']);
        File::deleteDirectory($this->vault);

        $this->assertSame(0, Artisan::call('jotter:restore', ['archive' => $summary['path'], '--force' => true, '--json' => true]));
        $result = json_decode(Artisan::output(), true);
        $this->assertSame(2, $result['files']);
        $this->assertFalse($result['pending_migrations']);

        $this->assertSame('Joé Ünicode', User::query()->where('email', 'joe@acme.test')->value('name'));
        $this->assertSame(1, Membership::query()->count());
        $this->assertSame('Estado', Note::query()->value('title'));
        $this->assertSame("# Estado\n\nprimeiro\n", file_get_contents($this->vault.'/projetos/x/estado.md'));
        $this->assertSame($png, file_get_contents($this->vault.'/logo.png'), 'binary files round-trip byte for byte');
        $this->assertDirectoryExists($this->vault.'/vazio');
    }

    public function test_restore_can_move_vaults_under_a_new_root(): void
    {
        Artisan::call('jotter:backup', ['--json' => true]);
        $path = json_decode(Artisan::output(), true)['path'];
        $newRoot = $this->vault.'-moved';

        $this->assertSame(0, Artisan::call('jotter:restore', ['archive' => $path, '--force' => true, '--vault-root' => $newRoot]), Artisan::output());

        $expected = $newRoot.'/'.basename($this->vault);
        $this->assertSame($expected, Workspace::query()->whereKey($this->workspace->id)->value('vault_path'));
        $this->assertFileExists($expected.'/projetos/x/estado.md');
    }

    public function test_a_backup_from_newer_code_is_refused_and_nothing_changes(): void
    {
        $zipPath = $this->dir.'/jotter-backup-forged.zip';
        File::ensureDirectoryExists($this->dir);
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE);
        $zip->addFromString('manifest.json', (string) json_encode([
            'format' => 'jotter-backup/1', 'migrations' => ['2099_01_01_000000_from_the_future'], 'tables' => ['users' => 0], 'workspaces' => [],
            'includes' => ['database' => true, 'vaults' => true],
        ]));
        $zip->addFromString('database/users.ndjson', '');
        $zip->close();

        $this->assertSame(1, Artisan::call('jotter:restore', ['archive' => $zipPath, '--force' => true]));
        $this->assertStringContainsString('newer Jotter', Artisan::output());
        $this->assertSame(1, User::query()->count(), 'users were not truncated');
    }

    public function test_a_corrupt_table_rolls_the_whole_database_restore_back(): void
    {
        $migrations = \Illuminate\Support\Facades\DB::table('migrations')->pluck('migration')->all();
        $zipPath = $this->dir.'/jotter-backup-corrupt.zip';
        File::ensureDirectoryExists($this->dir);
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE);
        $zip->addFromString('manifest.json', (string) json_encode([
            'format' => 'jotter-backup/1', 'migrations' => $migrations, 'tables' => ['users' => 0, 'memberships' => 1], 'workspaces' => [],
            'includes' => ['database' => true, 'vaults' => false],
        ]));
        $zip->addFromString('database/users.ndjson', '');
        $zip->addFromString('database/memberships.ndjson', "{not json\n");
        $zip->close();

        $this->assertSame(1, Artisan::call('jotter:restore', ['archive' => $zipPath, '--force' => true]));
        $this->assertSame(1, User::query()->count(), 'users were emptied by the archive, then rolled back');
        $this->assertSame(1, Membership::query()->count());
    }

    public function test_entries_escaping_the_vault_are_never_written(): void
    {
        Artisan::call('jotter:backup', ['--json' => true]);
        $path = json_decode(Artisan::output(), true)['path'];
        $zip = new ZipArchive;
        $zip->open($path);
        $zip->addFromString('vaults/'.$this->workspace->id.'/../../escaped.txt', 'pwned');
        $zip->close();

        Artisan::call('jotter:restore', ['archive' => $path, '--force' => true]);

        $this->assertFileDoesNotExist(dirname($this->vault).'/escaped.txt');
        $this->assertFileDoesNotExist(dirname($this->vault, 2).'/escaped.txt');
    }

    public function test_old_archives_are_pruned_and_the_document_root_is_refused(): void
    {
        File::ensureDirectoryExists($this->dir);
        foreach (['20260101-000000', '20260102-000000', '20260103-000000'] as $stamp) {
            File::put($this->dir.'/jotter-backup-'.$stamp.'.zip', 'old');
        }

        $this->assertSame(0, Artisan::call('jotter:backup', ['--keep' => 2]));
        $left = array_map('basename', File::glob($this->dir.'/jotter-backup-*.zip'));
        $this->assertCount(2, $left);
        $this->assertNotContains('jotter-backup-20260101-000000.zip', $left);

        $this->assertSame(1, Artisan::call('jotter:backup', ['--to' => public_path('leak.zip')]));
        $this->assertFileDoesNotExist(public_path('leak.zip'));
    }
}
