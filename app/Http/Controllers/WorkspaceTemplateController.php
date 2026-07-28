<?php

namespace App\Http\Controllers;

use App\Domain\Auth\Contracts\IdentityProvider;
use App\Domain\Templates\TemplateEngine;
use App\Domain\Vault\VaultPathGuard;
use App\Domain\Vault\VaultStorage;
use App\Models\Note;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

final class WorkspaceTemplateController extends Controller
{
    public function __construct(
        private readonly IdentityProvider $identityProvider,
        private readonly VaultStorage $vaultStorage,
        private readonly VaultPathGuard $pathGuard,
        private readonly TemplateEngine $templateEngine
    ) {}

    public function createFromTemplate(Request $request, int $workspaceId): JsonResponse
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

        $request->validate([
            'template_path' => ['required', 'string'],
            'target_path' => ['required', 'string'],
            'title' => ['nullable', 'string'],
        ]);

        $templatePath = $request->string('template_path')->trim()->toString();
        $targetPath = $request->string('target_path')->trim()->toString();

        if (! $this->vaultStorage->exists($workspace, $templatePath)) {
            return response()->json(['message' => "Template note not found: {$templatePath}"], 404);
        }

        $rawTemplate = $this->vaultStorage->readContents($workspace, $templatePath);
        $title = $request->input('title') ?: basename(str_replace('\\', '/', $targetPath), '.md');

        $renderedContent = $this->templateEngine->render($rawTemplate, [
            'title' => $title,
            'workspace' => $workspace->name,
        ]);

        $note = $this->vaultStorage->write($workspace, $targetPath, $renderedContent);

        return response()->json([
            'message' => 'Note created from template.',
            'data' => $note,
        ], 201);
    }

    public function dailyNote(Request $request, int $workspaceId, ?string $dateStr = null): JsonResponse
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

        $date = $dateStr ? Carbon::parse($dateStr) : now();
        $year = $date->format('Y');
        $month = $date->format('m');
        $day = $date->format('Y-m-d');

        $relativePath = "journal/{$year}/{$month}/{$day}.md";

        // Validate path safety (§8 S2)
        try {
            $this->pathGuard->resolve($workspace, $relativePath, mustExist: false, mustBeMarkdown: true);
        } catch (\Throwable) {
            return response()->json(['message' => 'Invalid daily note path pattern.'], 400);
        }

        // If daily note already exists, return existing note
        $existing = Note::query()->where('workspace_id', $workspaceId)->where('path', $relativePath)->first();
        if ($existing) {
            return response()->json(['data' => $existing]);
        }

        // Create daily note from template if _templates/daily.md exists, or fallback default
        $templatePath = '_templates/daily.md';
        if ($this->vaultStorage->exists($workspace, $templatePath)) {
            $raw = $this->vaultStorage->readContents($workspace, $templatePath);
            $content = $this->templateEngine->render($raw, [
                'title' => "Journal {$day}",
                'workspace' => $workspace->name,
                'date' => $date->toIso8601String(),
            ]);
        } else {
            $content = "# Journal {$day}\n\nDaily notes for {$day}.";
        }

        $note = $this->vaultStorage->write($workspace, $relativePath, $content);

        return response()->json(['data' => $note], 201);
    }
}
