<?php

namespace App\Http\Controllers;

use App\Models\Note;
use App\Models\Workspace;
use Illuminate\Http\Response;

final class LlmsTxtController extends Controller
{
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

    public function workspaceLlmsTxt(int $workspaceId): Response
    {
        $workspace = Workspace::query()->find($workspaceId);
        if (! $workspace) {
            return response('Workspace not found', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        $notes = Note::query()->where('workspace_id', $workspaceId)->get();

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
