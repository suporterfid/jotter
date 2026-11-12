<?php

namespace App\Console\Commands;

use App\Domain\Backup\InstanceBackup;
use App\Domain\Privacy\ErasureLog;
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
        {--erasure-log=* : Extra erasure logs to replay: an erasure-log.ndjson file or a newer backup archive (repeatable)}
        {--force : Do not ask for confirmation}
        {--json : Emit JSON}';

    protected $description = 'Restore a jotter:backup archive: replaces the database tables, writes the vault files back, and replays the erasure log.';

    /**
     * @return list<array<string, mixed>>
     */
    private function erasuresFrom(InstanceBackup $backup, string $source): array
    {
        if (! is_file($source)) {
            throw new RuntimeException("Erasure log source not found: {$source}");
        }
        $head = (string) file_get_contents($source, false, null, 0, 8);

        return str_starts_with($head, 'PK') || $head === \App\Domain\Backup\BackupCipher::MAGIC
            ? $backup->erasureLogOf($source)
            : ErasureLog::parse((string) file_get_contents($source));
    }

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

        // Block 4c: deletions made after this archive are only known to the erasure log.
        $extra = [];
        try {
            foreach ((array) $this->option('erasure-log') as $source) {
                $extra = [...$extra, ...$this->erasuresFrom($backup, (string) $source)];
            }
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $logMissing = ! (new ErasureLog)->exists() && $extra === [];
        if ($logMissing && ! $this->option('json')) {
            $this->warn('This server has no erasure log ('.ErasureLog::path().'). If tenants or accounts were deleted after this backup was made '
                .'(for example, when restoring onto a new host), they would come back. Pass --erasure-log=<the newest backup or its erasure-log.ndjson>.');
        }
        if ($logMissing) {
            if (! $this->option('force')) {
                $this->error('Refusing to restore without an erasure log. Pass --erasure-log=…, or --force if no deletion happened after this backup.');

                return self::FAILURE;
            }
        }

        if (! $this->option('force') && ! $this->confirm('This REPLACES every table in the archive (users, notes, tokens…) and overwrites vault files. Continue?', false)) {
            $this->comment('Nothing was changed.');

            return self::FAILURE;
        }

        try {
            $result = $backup->restore($archive, $this->option('vault-root') ? (string) $this->option('vault-root') : null, $extra);
        } catch (\Throwable $exception) {
            // The database part runs in one transaction, so it is unchanged here.
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($result + ['erasure_log_missing' => $logMissing], JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info(sprintf('Restored %d table(s), %d row(s), %d vault file(s).', $result['tables'], $result['rows'], $result['files']));
        $erasures = $result['erasures'];
        $this->line(sprintf('Erasure log: %d entr%s; deleted again: %d tenant(s), %d account(s).', $result['erasure_log'], $result['erasure_log'] === 1 ? 'y' : 'ies', $erasures['tenants'], $erasures['users']));
        foreach ($erasures['refused'] as $refused) {
            $this->warn('  not deleted again: '.$refused);
        }
        if ($result['pending_migrations']) {
            $this->warn('This code has newer migrations than the backup: run php artisan migrate --force.');
        }
        $this->line('Then run php artisan vault:reindex --all, and semantic:index if semantic search is on.');

        return self::SUCCESS;
    }
}
