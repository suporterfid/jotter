<?php

namespace App\Console\Commands;

use App\Domain\Semantic\SemanticIndexer;
use App\Models\Workspace;
use Illuminate\Console\Command;

/**
 * Semantic search index (#412). Scheduled every five minutes; embeds up to
 * --limit changed notes of opted-in workspaces per run. Also turns a workspace
 * on or off and rebuilds the index, which is only a projection of the notes.
 */
final class SemanticIndexCommand extends Command
{
    protected $signature = 'semantic:index
        {--workspace= : Workspace slug or id (required with --enable / --disable)}
        {--enable : Opt the workspace in: its note text will be sent to the embedding provider}
        {--disable : Opt the workspace out and delete its index}
        {--rebuild : Delete the index (of --workspace, or all) before indexing}
        {--limit=100 : Notes to embed in this run}
        {--json : Emit JSON}';

    protected $description = 'Build the semantic search index of opted-in workspaces (off unless JOTTER_SEMANTIC_ENABLED=true).';

    public function handle(SemanticIndexer $indexer): int
    {
        $workspace = null;
        $reference = (string) $this->option('workspace');
        if ($reference !== '') {
            $workspace = Workspace::query()->where('slug', $reference)
                ->when(ctype_digit($reference), static fn ($query) => $query->orWhere('id', (int) $reference))
                ->first();
            if ($workspace === null) {
                $this->error(sprintf('Workspace [%s] was not found.', $reference));

                return self::FAILURE;
            }
        }

        if (($this->option('enable') || $this->option('disable')) && $workspace === null) {
            $this->error('--enable and --disable need --workspace=<slug>.');

            return self::FAILURE;
        }
        if ($this->option('enable') && $workspace !== null) {
            $workspace->forceFill(['semantic_search' => true])->save();
            $this->info(sprintf('Semantic search enabled for [%s]; its note text will be sent to %s.', $workspace->slug, (string) config('jotter.semantic.base_url')));
        }
        if ($this->option('disable') && $workspace !== null) {
            $workspace->forceFill(['semantic_search' => false])->save();
            $removed = $indexer->forget($workspace);
            $this->info(sprintf('Semantic search disabled for [%s]; %d indexed chunk(s) deleted.', $workspace->slug, $removed));

            return self::SUCCESS;
        }
        if ($this->option('rebuild')) {
            $removed = $indexer->forget($workspace);
            $this->line(sprintf('Index cleared (%d chunk(s)).', $removed));
        }

        if (! SemanticIndexer::enabled()) {
            $this->comment('JOTTER_SEMANTIC_ENABLED is false: nothing is indexed and existing chunks are deleted.');
        }

        $stats = $indexer->run(max(1, (int) $this->option('limit')), $workspace);

        if ($this->option('json')) {
            $this->line((string) json_encode($stats));
        } else {
            $this->line(sprintf('indexed %d note(s), %d chunk(s); %d pending; %d removed.', $stats['indexed'], $stats['chunks'], $stats['pending'], $stats['removed']));
            if ($stats['error'] !== null) {
                $this->warn('Stopped early: '.$stats['error']);
            }
        }

        return $stats['error'] === null ? self::SUCCESS : self::FAILURE;
    }
}
