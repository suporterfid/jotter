<?php

namespace App\Console\Commands;

use App\Domain\Backup\BackupCipher;
use App\Domain\Backup\InstanceBackup;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * One ZIP with every Jotter table and every vault file (#390). Pure PHP, so it
 * runs from cron on shared hosting. See docs/deployment.md "Backup and restore".
 */
final class JotterBackupCommand extends Command
{
    protected $signature = 'jotter:backup
        {--to= : Output ZIP (default: JOTTER_BACKUP_PATH/jotter-backup-<timestamp>.zip)}
        {--keep= : Archives to keep in the default folder (default: JOTTER_BACKUP_KEEP, 7)}
        {--no-vaults : Database only}
        {--with-remote-vaults : Copy the files of every remote workspace (by default: S3 workspaces whose bucket is not versioned; never GitHub ones)}
        {--without-remote-vaults : Leave every remote workspace out, even S3 buckets without versioning (at your own risk)}
        {--no-database : Vault files only}
        {--unencrypted : Write a plain ZIP even when JOTTER_BACKUP_KEY is set}
        {--json : Emit JSON}';

    protected $description = 'Back up the whole instance (all Jotter tables and vault files) into one ZIP.';

    public function handle(InstanceBackup $backup): int
    {
        $directory = rtrim((string) config('jotter.backup.path'), '/');
        $encrypt = ! $this->option('unencrypted') && $backup->encrypts();
        $to = (string) ($this->option('to') ?: $directory.'/jotter-backup-'.now()->format('Ymd-His').'.zip'.($encrypt ? BackupCipher::EXTENSION : ''));
        if (str_starts_with((string) realpath(dirname($to)) ?: dirname($to), public_path()) || str_starts_with($to, public_path())) {
            $this->error('Refusing to write the backup under the document root: it holds password hashes and every note.');

            return self::FAILURE;
        }
        if ($this->option('with-remote-vaults') && $this->option('without-remote-vaults')) {
            $this->error('--with-remote-vaults and --without-remote-vaults cannot be used together.');

            return self::FAILURE;
        }
        if ($this->option('no-vaults') && $this->option('no-database')) {
            $this->error('--no-vaults and --no-database together leave nothing to back up.');

            return self::FAILURE;
        }

        try {
            $summary = $backup->create($to, withDatabase: ! $this->option('no-database'), withVaults: ! $this->option('no-vaults'), encrypt: $encrypt, withRemoteVaults: $this->option('with-remote-vaults') ? true : ($this->option('without-remote-vaults') ? false : null));
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $deleted = $this->option('to') ? [] : $backup->prune($directory, (int) ($this->option('keep') ?? config('jotter.backup.keep', 7)));

        if ($this->option('json')) {
            $this->line((string) json_encode($summary + ['pruned' => $deleted], JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info(sprintf('Backup written%s: %d table(s), %d row(s), %d vault file(s), %s.', $summary['encrypted'] ? ' (encrypted)' : '', $summary['tables'], $summary['rows'], $summary['files'], $this->human($summary['bytes'])));
        $this->line('  '.$summary['path']);
        foreach ($summary['remote_vaults'] as $remote) {
            $line = sprintf('  %s (%s): %s, %s', $remote['workspace'], $remote['driver'], $remote['included'] ? 'included' : 'not included', $remote['reason']);
            str_starts_with($remote['reason'], 'unreachable') || ($remote['driver'] === 's3' && ! $remote['included'] && $remote['reason'] !== 'bucket versioning on')
                ? $this->warn($line) : $this->line($line);
        }
        if ($deleted !== []) {
            $this->line('  pruned: '.implode(', ', $deleted));
        }
        $this->comment('  Copy it off this server: a backup on the same disk does not survive the disk.');
        if (! $summary['encrypted']) {
            $this->comment('  Not encrypted: it holds password hashes and every note. Set JOTTER_BACKUP_KEY (php artisan jotter:backup-key).');
        }

        return self::SUCCESS;
    }

    private function human(int $bytes): string
    {
        $units = ['B', 'KiB', 'MiB', 'GiB'];
        $value = (float) $bytes;
        $unit = 0;
        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return sprintf($unit === 0 ? '%d %s' : '%.1f %s', $value, $units[$unit]);
    }
}
