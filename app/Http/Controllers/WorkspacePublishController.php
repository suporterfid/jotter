<?php

namespace App\Http\Controllers;

use App\Domain\Auth\Contracts\IdentityProvider;
use App\Domain\Vault\MarkdownServerRenderer;
use App\Models\Note;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WorkspacePublishController extends Controller
{
    public function __construct(
        private readonly IdentityProvider $identityProvider,
        private readonly MarkdownServerRenderer $renderer
    ) {}

    public function publish(Request $request, int $workspaceId): JsonResponse
    {
        $subject = $this->identityProvider->resolveIdentity($request);
        if (! $subject) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (! $this->identityProvider->isAuthorizedForWorkspace($subject, $workspaceId)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $workspace = Workspace::query()->find($workspaceId);
        if (! $workspace) {
            return response()->json(['message' => 'Workspace not found.'], 404);
        }

        $siteDir = storage_path("app/public/sites/{$workspace->slug}");
        if (! is_dir($siteDir)) {
            mkdir($siteDir, 0755, true);
        }

        $notes = Note::query()->where('workspace_id', $workspaceId)->get();
        $publishedCount = 0;

        foreach ($notes as $note) {
            $fullPath = rtrim($workspace->vault_path, '/').'/'.$note->path;
            if (file_exists($fullPath)) {
                $markdown = file_get_contents($fullPath);
                $html = $this->renderer->render($markdown);

                $pageHtml = '<!DOCTYPE html><html><head><title>'.htmlspecialchars($note->title).'</title><style>body{font-family:sans-serif;max-width:800px;margin:2rem auto;padding:0 1rem;line-height:1.6;color:#333;}</style></head><body><h1>'.htmlspecialchars($note->title).'</h1>'.$html.'</body></html>';

                $outPath = $siteDir.'/'.str_replace('.md', '.html', $note->path);
                $outDir = dirname($outPath);
                if (! is_dir($outDir)) {
                    mkdir($outDir, 0755, true);
                }

                file_put_contents($outPath, $pageHtml);
                $publishedCount++;
            }
        }

        $publishedUrl = url("storage/sites/{$workspace->slug}/index.html");

        return response()->json([
            'message' => 'Workspace published successfully.',
            'workspace' => $workspace->name,
            'notes_published' => $publishedCount,
            'site_url' => $publishedUrl,
        ]);
    }
}
