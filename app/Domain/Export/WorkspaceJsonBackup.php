<?php

namespace App\Domain\Export;

use App\Domain\Vault\Store\VaultStoreResolver;
use App\Domain\Vault\VaultPathGuard;
use App\Models\Note;
use App\Models\Workspace;

/**
 * The JSON backup document (`GET /api/workspaces/{id}/export?format=json`) as a
 * reusable builder so CLI exports produce byte-compatible files.
 */
final class WorkspaceJsonBackup
{
    public const VERSION = '1.0';

    public function __construct(
        private readonly VaultPathGuard $pathGuard = new VaultPathGuard,
    ) {}

    /**
     * @param  iterable<Note>  $notes
     * @return array{version: string, workspace_slug: string, exported_at: string, notes: list<array{path: string, content: string}>}
     */
    public function build(Workspace $workspace, iterable $notes): array
    {
        $exported = [];
        $store = app(VaultStoreResolver::class)->for($workspace);
        foreach ($notes as $note) {
            try {
                $relative = $this->pathGuard->normalize($workspace, $note->path, mustBeMarkdown: false);
                $exported[] = ['path' => $note->path, 'content' => $store->read($relative)];
            } catch (\Throwable) {
                // File missing from the vault store: the projection is rebuildable, skip it.
            }
        }

        return [
            'version' => self::VERSION,
            'workspace_slug' => $workspace->slug,
            'exported_at' => now()->toIso8601String(),
            'notes' => $exported,
        ];
    }
}
