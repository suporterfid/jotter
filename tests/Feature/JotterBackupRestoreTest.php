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

    public function test_with_a_key_the_backup_is_encrypted_and_still_restores(): void
    {
        $key = \App\Domain\Backup\BackupCipher::generateKey();
        config(['jotter.backup.key' => $key]);

        $this->assertSame(0, Artisan::call('jotter:backup', ['--json' => true]));
        $summary = json_decode(Artisan::output(), true);
        $this->assertTrue($summary['encrypted']);
        $this->assertStringEndsWith('.zip.enc', $summary['path']);
        $this->assertTrue(\App\Domain\Backup\BackupCipher::isEncrypted($summary['path']));
        $raw = (string) file_get_contents($summary['path']);
        $this->assertStringNotContainsString('estado.md', $raw, 'file names inside are hidden');
        $this->assertStringNotContainsString('joe@acme.test', $raw);
        $this->assertSame([], glob($this->dir.'/*.zip') ?: [], 'no plain copy is left next to it');
        $this->assertDatabaseHas('audit_log', ['event' => 'instance.backed_up']);

        Membership::query()->delete();
        File::deleteDirectory($this->vault);

        $this->assertSame(0, Artisan::call('jotter:restore', ['archive' => $summary['path'], '--force' => true, '--json' => true]), Artisan::output());
        $this->assertSame(1, Membership::query()->count());
        $this->assertSame("# Estado\n\nprimeiro\n", file_get_contents($this->vault.'/projetos/x/estado.md'));
        $this->assertSame([], glob(storage_path('app/private/backups/.tmp-*')) ?: [], 'the decrypted copy is removed');

        // Without the key the archive cannot be read, and nothing changes.
        config(['jotter.backup.key' => \App\Domain\Backup\BackupCipher::generateKey()]);
        $this->assertSame(1, Artisan::call('jotter:restore', ['archive' => $summary['path'], '--force' => true]));
        $this->assertStringContainsString('JOTTER_BACKUP_PREVIOUS_KEYS', Artisan::output());

        // After a rotation the old key still opens it.
        config(['jotter.backup.previous_keys' => [$key]]);
        $this->assertSame(0, Artisan::call('jotter:restore', ['archive' => $summary['path'], '--force' => true]), Artisan::output());
    }

    public function test_unencrypted_on_request_and_pruning_counts_both_kinds(): void
    {
        config(['jotter.backup.key' => \App\Domain\Backup\BackupCipher::generateKey()]);

        $this->assertSame(0, Artisan::call('jotter:backup', ['--json' => true, '--unencrypted' => true]));
        $plain = json_decode(Artisan::output(), true);
        $this->assertFalse($plain['encrypted']);
        $this->assertStringEndsWith('.zip', $plain['path']);

        File::ensureDirectoryExists($this->dir);
        foreach (['20200101-000000.zip', '20200102-000000.zip.enc', '20200103-000000.zip.enc'] as $name) {
            File::put($this->dir.'/jotter-backup-'.$name, 'old');
        }
        $this->assertSame(0, Artisan::call('jotter:backup', ['--json' => true, '--keep' => 2]));
        $summary = json_decode(Artisan::output(), true);
        // Kept: the two backups made in this test (one plain, one encrypted); every older one goes.
        $this->assertSame(['jotter-backup-20200103-000000.zip.enc', 'jotter-backup-20200102-000000.zip.enc', 'jotter-backup-20200101-000000.zip'], $summary['pruned']);
        $this->assertCount(2, glob($this->dir.'/jotter-backup-*') ?: []);
    }

    public function test_backup_key_prints_a_usable_key(): void
    {
        $this->assertSame(0, Artisan::call('jotter:backup-key'));
        $output = Artisan::output();
        $this->assertSame(1, preg_match('/^JOTTER_BACKUP_KEY=(base64:\S+)$/m', $output, $match));

        (new \App\Domain\Backup\BackupCipher($match[1]))->assertUsable();
        $this->assertStringContainsString('OFF this server', $output);
    }

    public function test_a_restore_never_brings_a_deleted_account_back(): void
    {
        User::factory()->create(['email' => 'gone@acme.test']);
        $this->assertSame(0, Artisan::call('jotter:backup', ['--json' => true]));
        $archive = json_decode(Artisan::output(), true)['path'];

        $zip = new ZipArchive;
        $zip->open($archive);
        $this->assertSame(0, json_decode((string) $zip->getFromName('manifest.json'), true)['erasure_log']);
        $this->assertNotFalse($zip->locateName('erasure-log.ndjson'));
        $zip->close();

        $this->assertSame(0, Artisan::call('user:delete', ['email' => 'gone@acme.test', '--force' => true]));

        $status = Artisan::call('jotter:restore', ['archive' => $archive, '--force' => true]);
        $output = Artisan::output();
        $this->assertSame(0, $status, $output);
        $this->assertStringContainsString('deleted again: 0 tenant(s), 1 account(s)', $output);

        $this->assertNull(User::query()->where('email', 'gone@acme.test')->first(), 'the deleted account stays deleted');
        $this->assertNotNull(User::query()->where('email', 'joe@acme.test')->first());
        $this->assertDatabaseHas('audit_log', ['event' => 'instance.erasures_replayed']);
    }

    public function test_a_restore_never_brings_a_deleted_tenant_or_its_vault_back(): void
    {
        config(['vault.base_path' => storage_path('app/vaults')]);
        $other = Tenant::create(['slug' => 'other', 'name' => 'Other']);
        $otherVault = storage_path('app/vaults/other_'.uniqid());
        File::ensureDirectoryExists($otherVault);
        File::put($otherVault.'/segredo.md', "# Segredo\n");
        Workspace::create(['tenant_id' => $other->id, 'slug' => 'docs', 'name' => 'Docs', 'vault_path' => $otherVault]);

        try {
            Artisan::call('jotter:backup', ['--json' => true]);
            $archive = json_decode(Artisan::output(), true)['path'];
            $this->assertSame(0, Artisan::call('tenant:delete', ['slug' => 'other', '--force' => true]), Artisan::output());
            $this->assertDirectoryDoesNotExist($otherVault);

            $this->assertSame(0, Artisan::call('jotter:restore', ['archive' => $archive, '--force' => true]), Artisan::output());

            $this->assertNull(Tenant::query()->where('slug', 'other')->first());
            $this->assertDirectoryDoesNotExist($otherVault, 'the restored vault files are removed again');
            $this->assertNotNull(Tenant::query()->where('slug', 'acme')->first());
        } finally {
            File::deleteDirectory($otherVault);
        }
    }

    public function test_without_an_erasure_log_a_restore_needs_force_or_a_newer_backup_log(): void
    {
        User::factory()->create(['email' => 'gone@acme.test']);
        Artisan::call('jotter:backup', ['--json' => true]);
        $old = json_decode(Artisan::output(), true)['path'];
        Artisan::call('user:delete', ['email' => 'gone@acme.test', '--force' => true]);
        $this->travel(1)->minutes();
        Artisan::call('jotter:backup', ['--json' => true]);
        $newer = json_decode(Artisan::output(), true)['path'];

        // A new host: the live erasure log is gone.
        unlink(\App\Domain\Privacy\ErasureLog::path());

        $this->artisan('jotter:restore', ['archive' => $old])
            ->expectsOutputToContain('Refusing to restore without an erasure log')
            ->assertFailed();

        $this->artisan('jotter:restore', ['archive' => $old, '--erasure-log' => [$newer]])
            ->expectsConfirmation('This REPLACES every table in the archive (users, notes, tokens…) and overwrites vault files. Continue?', 'yes')
            ->expectsOutputToContain('deleted again: 0 tenant(s), 1 account(s)')
            ->assertSuccessful();

        $this->assertNull(User::query()->where('email', 'gone@acme.test')->first());
        $this->assertCount(1, (new \App\Domain\Privacy\ErasureLog)->entries(), 'the live log is rebuilt from the newer backup');
    }
}
