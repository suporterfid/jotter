<?php

namespace App\Http\Controllers;

use App\Domain\Auth\Contracts\IdentityProvider;
use App\Domain\Auth\NoteAccess;
use App\Models\Note;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class LlmsTxtController extends Controller
{
    public const DOCS_BASE_URL = 'https://github.com/suporterfid/jotter/blob/main/';

    /** @var array<string, string> title => repository path */
    public const GUIDES = [
        'Connect AI clients over MCP (Claude Code, Cursor, Claude Desktop)' => 'docs/mcp-clients.md',
        'MCP server reference' => 'docs/mcp.md',
        'Migrate from Obsidian' => 'docs/migrate-from-obsidian.md',
        'Migrate from Notion' => 'docs/migrate-from-notion.md',
        'Shared-hosting deployment' => 'docs/deployment.md',
        'Hosted operations (SSH + Artisan)' => 'docs/hosted-operations.md',
        'Architecture' => 'docs/architecture.md',
    ];

    public function __construct(
        private readonly IdentityProvider $identityProvider,
        private readonly NoteAccess $noteAccess,
    ) {}

    public function globalLlmsTxt(): Response
    {
        $workspaces = Workspace::query()->get();

        $content = "# Jotter Knowledge Base\n\n";
        $content .= "> Self-hosted Markdown Knowledge Base for cPanel & shared hosting.\n\n";
        $content .= "## Guides\n\n";
        foreach (self::GUIDES as $title => $path) {
            $content .= "- [{$title}](".self::DOCS_BASE_URL.$path.")\n";
        }
        $content .= "\n## Model Context Protocol\n\n";
        $content .= '- Endpoint: `POST '.rtrim((string) config('app.url'), '/')."/api/mcp` (Streamable HTTP, JSON-RPC 2.0)\n";
        $content .= "- Auth: `Authorization: Bearer <machine token>`; tools: list_workspaces, list_notes, read_note, search_notes, get_backlinks (read-only)\n\n";
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
