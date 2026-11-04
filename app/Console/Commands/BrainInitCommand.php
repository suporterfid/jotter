<?php

namespace App\Console\Commands;

use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Brain\BrainPack;
use App\Models\Workspace;
use Illuminate\Console\Command;

/**
 * Lays out the `brain` vault convention in a workspace. Idempotent: files that
 * already exist are reported as skipped and never overwritten.
 */
final class BrainInitCommand extends Command
{
    protected $signature = 'brain:init
                            {workspace : Workspace slug or id}
                            {--locale=pt-BR : pt-BR or en}
                            {--dry-run : List what would be written without writing}
                            {--json : Emit JSON}';

    protected $description = 'Create the brain vault layout (_brain/, projetos/, decisoes/, diario/, inbox/, pessoas/) in a workspace.';

    public function handle(BrainPack $pack): int
    {
        $reference = (string) $this->argument('workspace');
        $workspace = Workspace::query()->where('slug', $reference)->first()
            ?? (ctype_digit($reference) ? Workspace::query()->find((int) $reference) : null);
        if ($workspace === null) {
            $this->error(sprintf('Workspace [%s] was not found.', $reference));

            return self::FAILURE;
        }

        $locale = BrainPack::normalizeLocale((string) $this->option('locale'));
        $dryRun = (bool) $this->option('dry-run');
        $result = $pack->install($workspace, $locale, 'cli:brain:init', $dryRun);

        if (! $dryRun) {
            (new AuditRecorder)->record(
                event: AuditEvent::BRAIN_INITIALIZED,
                tenantId: $workspace->tenant_id,
                workspaceId: $workspace->id,
                actorId: 'cli:brain:init',
                metadata: ['locale' => $locale, 'written' => count($result['written']), 'skipped' => count($result['skipped'])],
            );
        }

        if ($this->option('json')) {
            $this->line((string) json_encode(['workspace' => $workspace->slug, 'locale' => $locale, 'dry_run' => $dryRun] + $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info(sprintf('%s brain layout in workspace %s (%s):', $dryRun ? 'Would create' : 'Created', $workspace->slug, $locale));
        foreach ($result['written'] as $path) {
            $this->line('  + '.$path);
        }
        foreach ($result['skipped'] as $path) {
            $this->line('  = '.$path.' (exists, kept)');
        }
        $this->comment('Next: issue agent tokens with write paths inbox/{client}/** and diario/** (see docs/mcp-clients.md).');

        return self::SUCCESS;
    }
}
