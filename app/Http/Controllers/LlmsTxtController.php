<?php

namespace App\Http\Controllers;

use App\Domain\Auth\Contracts\IdentityProvider;
use App\Domain\Auth\NoteAccess;
use App\Models\Note;
use App\Models\Workspace;
use Illuminate\Http\Response;
use Illuminate\Http\Request;

final class LlmsTxtController extends Controller
{
    public function __construct(
        private readonly IdentityProvider $identityProvider,
        private readonly NoteAccess $noteAccess,
    ) {}

    public function globalLlmsTxt(): Response
    {
        $workspaces = Workspace::query()->get();

        $content = "# Jotter Knowledge Base\n\n";
        $content .= "> Self-hosted Markdown Knowledge Base for cPanel & shared hosting.\n\n";
        $content .= "## Available Workspaces\n\n";

        foreach ($workspaces as $ws) {
            $content .= "- **{$ws->name}** (`{$ws->slug}`): `/api/workspaces/{$ws->id}/llms.txt`\n";
        }

        return response($content, 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    public function workspaceLlmsTxt(Request $request, int $workspaceId): Response
    {
        $subject = $request->attributes->get('authenticated_subject')
            ?? $this->identityProvider->resolveIdentity($request);
        if (! $subject || ! $this->identityProvider->isAuthorizedForWorkspace($subject, $workspaceId)) {
            return response('Forbidden', 403, ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        $workspace = Workspace::query()->find($workspaceId);
        if (! $workspace) {
            return response('Workspace not found', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        $notes = $this->noteAccess->scopeVisible(Note::query(), $subject, $workspaceId)->get();

        $content = "# Vault: {$workspace->name}\n\n";
        $content .= "## Notes Directory\n\n";

        foreach ($notes as $note) {
            $content .= "### {$note->title}\n";
            $content .= "- Path: `{$note->path}`\n";
            $content .= "- Updated: `{$note->updated_at?->toIso8601String()}`\n\n";
        }

        return response($content, 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
