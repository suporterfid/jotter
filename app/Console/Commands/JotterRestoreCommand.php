<?php

namespace App\Console\Commands;

use App\Domain\Backup\InstanceBackup;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Restores a `jotter:backup` archive (#390): replaces every table found in the
 * archive and writes the vault files back. Destructive by design, so it asks
 * for confirmation unless --force is given.
 */
final class JotterRestoreCommand extends Command
{
    protected $signature = 'jotter:restore
        {archive : Path to a jotter-backup-*.zip, or *.zip.enc (decrypted with JOTTER_BACKUP_KEY or JOTTER_BACKUP_PREVIOUS_KEYS)}
        {--vault-root= : Put each vault under this folder (keeps its folder name) and update vault paths}
        {--force : Do not ask for confirmation}
        {--json : Emit JSON}';

    protected $description = 'Restore a jotter:backup archive: replaces the database tables and writes the vault files back.';

    public function handle(InstanceBackup $backup): int
    {
        $archive = (string) $this->argument('archive');
        try {
            $manifest = $backup->inspect($archive);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if (! $this->option('json')) {
            $this->line(sprintf('Backup from %s (version %s): %d table(s), %d workspace(s).',
            (string) ($manifest['created_at'] ?? '?'), (string) ($manifest['version'] ?? 'unknown'), count((array) ($manifest['tables'] ?? [])), count((array) ($manifest['workspaces'] ?? []))));
        }

        if (! $this->option('force') && ! $this->confirm('This REPLACES every table in the archive (users, notes, tokens…) and overwrites vault files. Continue?', false)) {
            $this->comment('Nothing was changed.');

            return self::FAILURE;
        }

        try {
            $result = $backup->restore($archive, $this->option('vault-root') ? (string) $this->option('vault-root') : null);
        } catch (\Throwable $exception) {
            // The database part runs in one transaction, so it is unchanged here.
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info(sprintf('Restored %d table(s), %d row(s), %d vault file(s).', $result['tables'], $result['rows'], $result['files']));
        if ($result['pending_migrations']) {
            $this->warn('This code has newer migrations than the backup: run php artisan migrate --force.');
        }
        $this->line('Then run php artisan vault:reindex --all, and semantic:index if semantic search is on.');

        return self::SUCCESS;
    }
}
