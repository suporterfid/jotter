<?php

namespace App\Console\Commands;

use App\Domain\Vault\NoteTrash;
use Illuminate\Console\Command;

final class VaultPurgeTrashCommand extends Command
{
    protected $signature = 'vault:purge-trash
                            {--days= : Permanently delete notes older than N days}
                            {--batch= : Maximum number of notes to purge in this run}';

    protected $description = 'Permanently delete expired notes from the vault trash';

    public function handle(NoteTrash $trash): int
    {
        $days = $this->positiveOption('days', (int) config('jotter.trash.retention_days', 30));
        $batchSize = $this->positiveOption('batch', (int) config('jotter.trash.purge_batch_size', 100));

        if ($days === null || $batchSize === null) {
            return self::FAILURE;
        }

        $purged = $trash->purgeExpired($days, $batchSize);

        $this->info("Purged {$purged} trashed note(s) older than {$days} days.");

        return self::SUCCESS;
    }

    private function positiveOption(string $name, int $default): ?int
    {
        $value = $this->option($name);
        $value = (string) ($value === null || $value === '' ? $default : $value);

        if (! ctype_digit($value) || (int) $value < 1) {
            $this->error("The --{$name} option must be a positive integer.");

            return null;
        }

        return (int) $value;
    }
}
