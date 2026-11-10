<?php

namespace App\Domain\Backup;

use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditRecorder;
use App\Models\Workspace;
use App\Support\ReleaseVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use ZipArchive;

/**
 * Whole-instance backup and restore (#390), in pure PHP so it runs where
 * `exec` and `mysqldump` are disabled (spec §4).
 *
 * Archive layout:
 * - `manifest.json`: format, version, migrations, tables with row counts, workspaces;
 * - `database/<table>.ndjson`: one JSON object per row, table names without DB_PREFIX
 *   so an archive restores into an installation with another prefix; values
 *   that are not valid UTF-8 are stored as `{"__b64": "…"}`;
 * - `vaults/<workspace id>/…`: every file of every vault, as on disk.
 *
 * Only this installation's tables are read (those carrying DB_PREFIX, never
 * the GrandpaSSOn tables that may share the database). Ephemeral state and
 * rebuildable projections listed in `jotter.backup.exclude_tables` are left out.
 *
 * With JOTTER_BACKUP_KEY set, the finished ZIP is encrypted as a whole
 * (BackupCipher, `*.zip.enc`): file names inside are hidden too. Inspect and
 * restore decrypt it to a private temporary file first and delete it after.
 */
final class InstanceBackup
{
    public const FORMAT = 'jotter-backup/1';

    public function __construct(
        private readonly AuditRecorder $audit = new AuditRecorder,
        private readonly ?BackupCipher $cipher = null,
    ) {}

    public function encrypts(): bool
    {
        return $this->cipher()->enabled();
    }

    /**
     * @param  bool  $encrypt  false writes a plain ZIP even when JOTTER_BACKUP_KEY is set
     * @return array{path: string, tables: int, rows: int, files: int, bytes: int, encrypted: bool}
     */
    public function create(string $zipPath, bool $withDatabase = true, bool $withVaults = true, bool $encrypt = true): array
    {
        $encrypt = $encrypt && $this->encrypts();
        if ($encrypt) {
            $this->cipher()->assertUsable();
        }
        $target = $zipPath;
        File::ensureDirectoryExists(dirname($target), 0750);
        $scratch = storage_path('app/private/backups/.tmp-'.bin2hex(random_bytes(6)));
        File::ensureDirectoryExists($scratch, 0700);
        // When encrypting, the plain ZIP only ever exists in the private scratch folder.
        $zipPath = $encrypt ? $scratch.'/archive.zip' : $target;

        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Unable to create the backup at {$zipPath}.");
        }

        $tables = [];
        $rows = 0;
        try {
            if ($withDatabase) {
                foreach ($this->tables() as $table) {
                    $file = $scratch.'/'.$table.'.ndjson';
                    $count = $this->dumpTable($table, $file);
                    $zip->addFile($file, 'database/'.$table.'.ndjson');
                    $tables[$table] = $count;
                    $rows += $count;
                }
            }

            $files = 0;
            $workspaces = [];
            foreach (Workspace::query()->orderBy('id')->get(['id', 'slug', 'vault_path']) as $workspace) {
                $workspaces[] = ['id' => $workspace->id, 'slug' => $workspace->slug, 'vault_path' => $workspace->vault_path];
                if ($withVaults) {
                    $files += $this->addVault($zip, (string) $workspace->vault_path, 'vaults/'.$workspace->id.'/');
                }
            }

            $zip->addFromString('manifest.json', (string) json_encode([
                'format' => self::FORMAT,
                'created_at' => now()->toIso8601String(),
                'version' => ReleaseVersion::current(),
                'app_url' => (string) config('app.url'),
                'migrations' => $withDatabase ? DB::table('migrations')->orderBy('id')->pluck('migration')->all() : [],
                'tables' => $tables,
                'workspaces' => $workspaces,
                'includes' => ['database' => $withDatabase, 'vaults' => $withVaults],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            if (! $zip->close()) {
                throw new RuntimeException("Unable to write the backup at {$zipPath}.");
            }
            if ($encrypt) {
                $this->cipher()->encryptFile($zipPath, $target);
            }
        } finally {
            File::deleteDirectory($scratch);
        }

        $this->audit->record(event: AuditEvent::INSTANCE_BACKED_UP, actorId: 'cli:jotter:backup', metadata: [
            'path' => basename($target), 'tables' => count($tables), 'rows' => $rows, 'files' => $files, 'encrypted' => $encrypt,
        ]);

        return ['path' => $target, 'tables' => count($tables), 'rows' => $rows, 'files' => $files, 'bytes' => (int) filesize($target), 'encrypted' => $encrypt];
    }

    /**
     * Reads an archive's manifest without restoring anything.
     *
     * @return array<string, mixed>
     */
    public function inspect(string $zipPath): array
    {
        return $this->withArchive($zipPath, fn (ZipArchive $zip): array => $this->manifest($zip));
    }

    /**
     * Replaces every table found in the archive and writes the vault files back.
     * With $vaultRoot, each vault goes to `<vaultRoot>/<original folder name>` and
     * `workspaces.vault_path` is updated to match.
     *
     * @return array{tables: int, rows: int, files: int, pending_migrations: bool}
     */
    public function restore(string $zipPath, ?string $vaultRoot = null): array
    {
        [$manifest, $tables, $rows, $files] = $this->withArchive($zipPath, function (ZipArchive $zip) use ($vaultRoot): array {
            $manifest = $this->manifest($zip);
            $this->assertCompatible($manifest);

            [$tables, $rows] = ($manifest['includes']['database'] ?? true) ? $this->restoreDatabase($zip, (array) $manifest['tables']) : [0, 0];
            $files = ($manifest['includes']['vaults'] ?? true) ? $this->restoreVaults($zip, (array) $manifest['workspaces'], $vaultRoot) : 0;

            return [$manifest, $tables, $rows, $files];
        });

        $known = array_map(static fn (string $path): string => basename($path, '.php'), File::glob(database_path('migrations/*.php')));
        $pending = array_diff($known, (array) $manifest['migrations']) !== [];

        $this->audit->record(event: AuditEvent::INSTANCE_RESTORED, actorId: 'cli:jotter:restore', metadata: [
            'archive' => basename($zipPath), 'created_at' => $manifest['created_at'] ?? null, 'tables' => $tables, 'rows' => $rows, 'files' => $files,
        ]);

        return ['tables' => $tables, 'rows' => $rows, 'files' => $files, 'pending_migrations' => $pending];
    }

    /**
     * Archives in `jotter.backup.path` beyond the newest $keep are deleted.
     *
     * @return list<string> deleted file names
     */
    public function prune(string $directory, int $keep): array
    {
        if ($keep < 1 || ! is_dir($directory)) {
            return [];
        }
        $archives = array_merge(
            File::glob(rtrim($directory, '/').'/jotter-backup-*.zip'),
            File::glob(rtrim($directory, '/').'/jotter-backup-*.zip'.BackupCipher::EXTENSION),
        );
        rsort($archives);
        $deleted = [];
        foreach (array_slice($archives, $keep) as $old) {
            if (@unlink($old)) {
                $deleted[] = basename($old);
            }
        }

        return $deleted;
    }

    /**
     * This installation's tables, without the connection prefix.
     *
     * @return list<string>
     */
    public function tables(): array
    {
        $prefix = (string) DB::connection()->getTablePrefix();
        $exclude = (array) config('jotter.backup.exclude_tables', []);
        $ssoPrefix = (string) config('jotter.sso.db_prefix', '');
        $names = [];
        foreach (Schema::getTables() as $table) {
            $name = (string) ($table['name'] ?? '');
            if ($prefix !== '' && ! str_starts_with($name, $prefix)) {
                continue;
            }
            // GrandpaSSOn's tables may live in the same database under their own prefix.
            if ($ssoPrefix !== '' && $ssoPrefix !== $prefix && str_starts_with($name, $ssoPrefix)) {
                continue;
            }
            $bare = substr($name, strlen($prefix));
            if ($bare !== '' && ! in_array($bare, $exclude, true)) {
                $names[] = $bare;
            }
        }
        sort($names);

        return array_values(array_unique($names));
    }

    private function dumpTable(string $table, string $file): int
    {
        $handle = fopen($file, 'wb');
        if ($handle === false) {
            throw new RuntimeException("Unable to write {$file}.");
        }

        $count = 0;
        $query = DB::table($table);
        $rows = Schema::hasColumn($table, 'id') ? $query->orderBy('id')->lazyById(500) : $query->cursor();
        foreach ($rows as $row) {
            $encoded = [];
            foreach ((array) $row as $column => $value) {
                $encoded[$column] = is_string($value) && ! mb_check_encoding($value, 'UTF-8') ? ['__b64' => base64_encode($value)] : $value;
            }
            fwrite($handle, (string) json_encode($encoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)."\n");
            $count++;
        }
        fclose($handle);

        return $count;
    }

    private function addVault(ZipArchive $zip, string $vaultPath, string $prefix): int
    {
        $root = realpath($vaultPath);
        if ($root === false || ! is_dir($root)) {
            return 0;
        }

        $count = 0;
        $zip->addEmptyDir(rtrim($prefix, '/'));
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        /** @var SplFileInfo $entry */
        foreach ($iterator as $entry) {
            if ($entry->isLink()) {
                continue;
            }
            $relative = ltrim(str_replace('\\', '/', substr($entry->getPathname(), strlen($root))), '/');
            if ($entry->isDir()) {
                $zip->addEmptyDir($prefix.$relative);
            } elseif ($entry->isFile()) {
                $zip->addFile($entry->getPathname(), $prefix.$relative);
                $count++;
            }
        }

        return $count;
    }

    /**
     * Opens the archive (decrypting an encrypted one to a private temporary
     * file first), runs $callback, and always closes and removes the copy.
     *
     * @template T
     * @param  callable(ZipArchive): T  $callback
     * @return T
     */
    private function withArchive(string $path, callable $callback): mixed
    {
        $plain = null;
        if (is_file($path) && BackupCipher::isEncrypted($path)) {
            $scratch = storage_path('app/private/backups/.tmp-'.bin2hex(random_bytes(6)));
            File::ensureDirectoryExists($scratch, 0700);
            $plain = $scratch.'/archive.zip';
            try {
                $this->cipher()->decryptFile($path, $plain);
            } catch (RuntimeException $exception) {
                File::deleteDirectory($scratch);

                throw $exception;
            }
        }

        try {
            $zip = $this->open($plain ?? $path);
            try {
                return $callback($zip);
            } finally {
                $zip->close();
            }
        } finally {
            if ($plain !== null) {
                File::deleteDirectory(dirname($plain));
            }
        }
    }

    private function cipher(): BackupCipher
    {
        return $this->cipher ?? BackupCipher::fromConfig();
    }

    private function open(string $zipPath): ZipArchive
    {
        $zip = new ZipArchive;
        if (! is_file($zipPath) || $zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException("Unable to open the backup at {$zipPath}.");
        }

        return $zip;
    }

    /**
     * @return array<string, mixed>
     */
    private function manifest(ZipArchive $zip): array
    {
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        if (! is_array($manifest) || ($manifest['format'] ?? null) !== self::FORMAT) {
            throw new RuntimeException('Not a Jotter backup (manifest.json missing or of another format).');
        }

        return $manifest;
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function assertCompatible(array $manifest): void
    {
        $known = array_map(static fn (string $path): string => basename($path, '.php'), File::glob(database_path('migrations/*.php')));
        $unknown = array_values(array_diff((array) ($manifest['migrations'] ?? []), $known));
        if ($unknown !== []) {
            throw new RuntimeException('The backup comes from a newer Jotter (unknown migrations: '.implode(', ', array_slice($unknown, 0, 3)).'). Upgrade the code first, then restore.');
        }
    }

    /**
     * @param array<string, int> $tables
     * @return array{0: int, 1: int}
     */
    private function restoreDatabase(ZipArchive $zip, array $tables): array
    {
        $mysql = DB::connection()->getDriverName() === 'mysql';
        $restoredTables = 0;
        $restoredRows = 0;

        if ($mysql) {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
        }
        try {
            // DELETE (not TRUNCATE, which commits) inside one transaction: a failure
            // halfway leaves the database as it was before the restore.
            DB::transaction(function () use ($zip, $tables, &$restoredTables, &$restoredRows): void {
            foreach (array_keys($tables) as $table) {
                if (! Schema::hasTable($table)) {
                    continue;
                }
                // Generated columns are computed by MySQL and refuse explicit values.
                $columns = [];
                foreach (Schema::getColumns($table) as $column) {
                    if (empty($column['generation'])) {
                        $columns[(string) $column['name']] = true;
                    }
                }
                $stream = $zip->getStream('database/'.$table.'.ndjson');
                if ($stream === false) {
                    throw new RuntimeException("The backup lacks database/{$table}.ndjson.");
                }

                DB::table($table)->delete();
                $batch = [];
                while (($line = fgets($stream)) !== false) {
                    $line = trim($line);
                    if ($line === '') {
                        continue;
                    }
                    $row = (array) json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                    $decoded = [];
                    foreach ($row as $column => $value) {
                        if (isset($columns[$column])) {
                            $decoded[$column] = is_array($value) && array_key_exists('__b64', $value) ? base64_decode((string) $value['__b64'], true) : $value;
                        }
                    }
                    $batch[] = $decoded;
                    if (count($batch) >= 200) {
                        DB::table($table)->insert($batch);
                        $restoredRows += count($batch);
                        $batch = [];
                    }
                }
                fclose($stream);
                if ($batch !== []) {
                    DB::table($table)->insert($batch);
                    $restoredRows += count($batch);
                }
                $restoredTables++;
            }
            });
        } finally {
            if ($mysql) {
                DB::statement('SET FOREIGN_KEY_CHECKS=1');
            }
        }

        return [$restoredTables, $restoredRows];
    }

    /**
     * @param list<array{id: int, slug: string, vault_path: string}> $workspaces
     */
    private function restoreVaults(ZipArchive $zip, array $workspaces, ?string $vaultRoot): int
    {
        $targets = [];
        foreach ($workspaces as $workspace) {
            $path = (string) $workspace['vault_path'];
            if ($vaultRoot !== null) {
                $path = rtrim($vaultRoot, '/').'/'.basename(rtrim($path, '/'));
                Workspace::query()->whereKey($workspace['id'])->update(['vault_path' => $path]);
            }
            $targets[(int) $workspace['id']] = rtrim($path, '/');
        }

        $files = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (preg_match('#^vaults/(\d+)/(.*)$#', $name, $match) !== 1 || ! isset($targets[(int) $match[1]])) {
                continue;
            }
            $relative = $match[2];
            // Never write outside the vault (zip slip).
            if ($relative === '' || str_contains('/'.$relative.'/', '/../') || str_starts_with($relative, '/') || str_contains($relative, '\\')) {
                continue;
            }
            $destination = $targets[(int) $match[1]].'/'.$relative;
            if (str_ends_with($name, '/')) {
                File::ensureDirectoryExists($destination, 0755);

                continue;
            }
            File::ensureDirectoryExists(dirname($destination), 0755);
            $stream = $zip->getStream($name);
            if ($stream === false) {
                continue;
            }
            $out = fopen($destination, 'wb');
            if ($out !== false) {
                stream_copy_to_stream($stream, $out);
                fclose($out);
                $files++;
            }
            fclose($stream);
        }

        return $files;
    }
}
