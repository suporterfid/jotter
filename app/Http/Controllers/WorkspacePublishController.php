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
            return response()->json(['message' => __('messages.unauthenticated')], 401);
        }

        if (! $this->identityProvider->isAuthorizedForWorkspace($subject, $workspaceId)) {
            return response()->json(['message' => __('messages.forbidden')], 403);
        }

        $workspace = Workspace::query()->find($workspaceId);
        if (! $workspace) {
            return response()->json(['message' => __('messages.workspace_not_found')], 404);
        }

        $siteDir = storage_path("app/public/sites/{$workspace->slug}");
        if (! is_dir($siteDir)) {
            mkdir($siteDir, 0755, true);
        }

        $this->copyPublishAsset(resource_path('views/publish/publish.css'), $siteDir.'/publish.css');
        $this->copyPublishAsset(resource_path('views/publish/publish-theme.js'), $siteDir.'/publish-theme.js');

        // Copy self-hosted UI font assets for offline rendering.
        $fontsDir = $siteDir.'/fonts';
        if (! is_dir($fontsDir)) {
            mkdir($fontsDir, 0755, true);
        }
        $fontSourceDir = base_path('frontend/src/assets/fonts');
        if (is_dir($fontSourceDir)) {
            foreach (glob($fontSourceDir.'/*.woff2') as $fontFile) {
                $this->copyPublishAsset($fontFile, $fontsDir.'/'.basename($fontFile));
            }
        }

        $locale = $subject->locale;
        $direction = $this->publicDirection($locale);
        $themeLabels = [
            'preference' => __('messages.theme_preference'),
            'system' => __('messages.theme_system'),
            'light' => __('messages.theme_light'),
            'dark' => __('messages.theme_dark'),
        ];

        $notes = Note::query()->where('workspace_id', $workspaceId)->orderBy('path')->get();
        $publishedCount = 0;
        $publishedPages = [];

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
                    'locale' => $locale,
                    'direction' => $direction,
                    'themeLabels' => $themeLabels,
                ])->render();

                $outPath = $siteDir.'/'.$relPath;
                $outDir = dirname($outPath);
                if (! is_dir($outDir)) {
                    mkdir($outDir, 0755, true);
                }

                file_put_contents($outPath, $pageHtml);
                $publishedCount++;
                $publishedPages[] = ['title' => $note->title, 'href' => $relPath];
            }
        }

        // The site needs a landing page: nothing above ever writes index.html,
        // and there's no guarantee a note is published at that exact path.
        $indexLinks = collect($publishedPages)
            ->map(fn ($p) => '<li><a href="'.e($p['href']).'">'.e($p['title']).'</a></li>')
            ->implode('');
        $indexHtml = view('publish.page', [
            'title' => $workspace->name,
            'html' => '<ul class="publish-index">'.$indexLinks.'</ul>',
            'assetPrefix' => '',
            'locale' => $locale,
            'direction' => $direction,
            'themeLabels' => $themeLabels,
        ])->render();
        file_put_contents($siteDir.'/index.html', $indexHtml);

        $publishedUrl = url("storage/sites/{$workspace->slug}/index.html");

        return response()->json([
            'message' => __('messages.workspace_published_successfully'),
            'workspace' => $workspace->name,
            'notes_published' => $publishedCount,
            'site_url' => $publishedUrl,
        ]);
    }

    private function copyPublishAsset(string $source, string $destination): void
    {
        if (! is_file($source)) {
            return;
        }

        $directory = dirname($destination);
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        copy($source, $destination);
    }

    private function publicDirection(string $locale): string
    {
        $language = strtolower(strtok(str_replace('_', '-', $locale), '-'));

        return in_array($language, ['ar', 'he'], true) ? 'rtl' : 'ltr';
    }
}
