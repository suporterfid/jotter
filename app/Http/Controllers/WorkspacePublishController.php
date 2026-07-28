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

        // Copy publish.css asset
        $publishCssSource = resource_path('views/publish/publish.css');
        if (file_exists($publishCssSource)) {
            copy($publishCssSource, $siteDir.'/publish.css');
        }

        // Copy self-hosted Open Sans font assets for offline rendering
        $fontsDir = $siteDir.'/fonts';
        if (! is_dir($fontsDir)) {
            mkdir($fontsDir, 0755, true);
        }
        $fontSourceDir = base_path('frontend/src/assets/fonts');
        if (is_dir($fontSourceDir)) {
            foreach (glob($fontSourceDir.'/*.woff2') as $fontFile) {
                copy($fontFile, $fontsDir.'/'.basename($fontFile));
            }
        }

        $notes = Note::query()->where('workspace_id', $workspaceId)->get();
        $publishedCount = 0;

        foreach ($notes as $note) {
            $fullPath = rtrim($workspace->vault_path, '/').'/'.$note->path;
            if (file_exists($fullPath)) {
                $markdown = file_get_contents($fullPath);
                $html = $this->renderer->render($markdown);

                $relPath = str_replace('.md', '.html', $note->path);
                $depth = substr_count(trim($relPath, '/'), '/');
                $assetPrefix = $depth > 0 ? str_repeat('../', $depth) : '';

                $pageHtml = view('publish.page', [
                    'title' => $note->title,
                    'html' => $html,
                    'assetPrefix' => $assetPrefix,
                ])->render();

                $outPath = $siteDir.'/'.$relPath;
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
