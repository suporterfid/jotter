<?php

namespace App\Console\Commands;

use App\Domain\Backup\BackupCipher;
use Illuminate\Console\Command;

/**
 * Prints a new random key for encrypted backups (LGPD art. 46). It does not
 * edit .env: on shared hosting the operator pastes it there and keeps a copy
 * off the server.
 */
final class JotterBackupKeyCommand extends Command
{
    protected $signature = 'jotter:backup-key';

    protected $description = 'Generate a key for encrypted backups (JOTTER_BACKUP_KEY).';

    public function handle(): int
    {
        $key = BackupCipher::generateKey();

        $this->line('JOTTER_BACKUP_KEY='.$key);
        $this->newLine();
        $this->comment('1. Put this line in .env (it is not saved anywhere else).');
        $this->comment('2. Keep a copy OFF this server, e.g. in a password manager: without it no backup can be restored.');
        $this->comment('3. Rotating? Move the old key to JOTTER_BACKUP_PREVIOUS_KEYS so older backups still open.');

        return self::SUCCESS;
    }
}
