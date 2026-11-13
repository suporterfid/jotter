<?php

namespace App\Console\Commands;

use App\Domain\Vault\Move\VaultMoveRefused;
use App\Domain\Vault\Move\VaultMover;
use App\Models\TenantVaultStore;
use App\Models\VaultMove;
use App\Models\Workspace;
use Illuminate\Console\Command;

/**
 * Change the store that holds a workspace's vault (#437, D9). Starts the move
 * (preflight + read-only), and the cron `vault:process-moves` copies it; --sync
 * copies it now. --rollback puts the workspace back on the retained source.
 */
final class VaultMoveCommand extends Command
{
    protected $signature = 'vault:move
        {workspace : Workspace slug or id}
        {--to= : Destination vault store id (see the tenant Storage screen)}
        {--local : Move back to the workspace\'s local folder}
        {--sync : Copy everything now instead of leaving it to the cron}
        {--rollback : Return to the source of the last completed move (within retention)}
        {--force : With --rollback, roll back even if notes changed after the move}
        {--json : Emit JSON}';

    protected $description = 'Move a workspace vault between stores (local folder, S3-compatible, GitHub), or roll a move back.';

    public function handle(VaultMover $mover): int
    {
        $reference = (string) $this->argument('workspace');
        $workspace = Workspace::query()->where('slug', $reference)
            ->when(ctype_digit($reference), static fn ($q) => $q->orWhere('id', (int) $reference))
            ->first();
        if ($workspace === null) {
            $this->error("Workspace [{$reference}] was not found.");

            return self::FAILURE;
        }

        try {
            if ($this->option('rollback')) {
                $move = VaultMove::query()->where('workspace_id', $workspace->id)->where('status', VaultMove::COMPLETED)->latest('id')->first();
                if ($move === null) {
                    $this->error('No completed move with a retained source to roll back.');

                    return self::FAILURE;
                }

                return $this->report($mover->rollback($move, 'cli:vault:move', (bool) $this->option('force')));
            }

            $target = null;
            if ($this->option('local') === (filled($this->option('to')))) {
                $this->error('Give exactly one of --to=<store id> or --local.');

                return self::FAILURE;
            }
            if (filled($this->option('to'))) {
                $target = TenantVaultStore::query()->find((int) $this->option('to'));
                if ($target === null) {
                    $this->error('Vault store [' . $this->option('to') . '] was not found.');

                    return self::FAILURE;
                }
            }

            $move = $mover->start($workspace, $target, 'cli:vault:move');
            if ($this->option('sync')) {
                $move = $mover->runToCompletion($move, (int) config('jotter.vault_stores.move_batch_size', 200));
            }

            return $this->report($move);
        } catch (VaultMoveRefused $refused) {
            $this->error('Refused: '.$refused->getMessage());

            return self::FAILURE;
        }
    }

    private function report(VaultMove $move): int
    {
        $data = $move->toApiArray();
        if ($this->option('json')) {
            $this->line((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info(sprintf('Move #%d: %s, %d/%d files (%d%%).', $data['id'], $data['status'], $data['files_copied'], $data['files_total'], $data['percent']));
            if ($data['error']) {
                $this->error($data['error']);
            }
            if ($data['status'] === VaultMove::COPYING) {
                $this->line('The workspace is read-only until vault:process-moves (cron, every minute) finishes the copy.');
            }
            if ($data['retain_until']) {
                $this->line("The source is kept until {$data['retain_until']} (vault:move --rollback).");
            }
        }

        return $move->status === VaultMove::FAILED ? self::FAILURE : self::SUCCESS;
    }
}
