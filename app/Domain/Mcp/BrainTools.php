<?php

namespace App\Domain\Mcp;

use App\Domain\Auth\AuthenticatedSubject;
use App\Domain\Auth\Contracts\IdentityProvider;
use App\Domain\Auth\NoteAccess;
use App\Domain\Links\OutgoingLinks;
use App\Domain\Search\WorkspaceSearch;
use App\Domain\Vault\VaultStorage;
use App\Models\AuditLog;
use App\Models\Note;
use App\Models\NoteRevision;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Read tools that let an agent orient itself in a "brain" vault with one call
 * (brain plan PR 4, R5), plus `search` / `fetch` in the shape ChatGPT's
 * connectors and Deep Research expect. Everything is ACL-filtered.
 */
final class BrainTools
{
    public const CONTEXT_MAX_BYTES = 24576;
    public const README_PATH = '_brain/README.md';
    public const INSTRUCTIONS_PATH = '_brain/instructions.md';
    public const SEARCH_LIMIT = 20;

    private const PROJECT_STATE_PATTERN = 'projetos/%/estado.md';
    private const PROJECT_FIELDS = ['status', 'proximo_passo', 'atualizado', 'dono'];

    public function __construct(
        private readonly IdentityProvider $identityProvider,
        private readonly NoteAccess $noteAccess,
        private readonly VaultStorage $storage = new VaultStorage,
    ) {}

    /**
     * README, instructions, every `projetos/<slug>/estado.md` front matter, and
     * the 10 latest changes, trimmed (lowest priority first) to 24 KB of JSON.
     *
     * @return array<string, mixed>
     */
    public function context(AuthenticatedSubject $subject, Workspace $workspace): array
    {
        $context = [
            'workspace' => ['id' => $workspace->id, 'slug' => $workspace->slug, 'name' => $workspace->name],
            'readme' => $this->readVisible($subject, $workspace, self::README_PATH),
            'instructions' => $this->readVisible($subject, $workspace, self::INSTRUCTIONS_PATH),
            'projects' => $this->projects($subject, $workspace),
            'recent_changes' => $this->recentChanges($subject, $workspace, null, 10),
            'truncated' => false,
        ];

        return $this->fitToBudget($context);
    }

    /**
     * Latest revisions of notes the subject can see, newest first, with the
     * acting user and — for agent writes — the MCP client slug.
     *
     * @return list<array<string, mixed>>
     */
    public function recentChanges(AuthenticatedSubject $subject, Workspace $workspace, ?CarbonImmutable $since, int $limit): array
    {
        $limit = max(1, min($limit, 100));
        $visibleIds = $this->noteAccess->scopeVisible(Note::query(), $subject, $workspace->id)->select('notes.id');

        $revisions = NoteRevision::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('note_id', $visibleIds)
            ->when($since !== null, static fn ($query) => $query->where('created_at', '>=', $since))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'note_id', 'content_hash', 'actor_id', 'created_at']);

        if ($revisions->isEmpty()) {
            return [];
        }

        $notes = Note::query()->whereIn('id', $revisions->pluck('note_id')->unique())->get(['id', 'path', 'title'])->keyBy('id');
        $clients = $this->clientsByWrite($revisions);
        $users = User::query()
            ->whereIn('id', $revisions->pluck('actor_id')->filter(static fn ($id): bool => ctype_digit((string) $id))->unique())
            ->get(['id', 'name'])
            ->keyBy('id');

        return $revisions->map(static function (NoteRevision $revision) use ($notes, $clients, $users): array {
            $note = $notes->get($revision->note_id);
            $actorId = (string) $revision->actor_id;

            return [
                'path' => $note?->path,
                'title' => $note?->title,
                'updated_at' => $revision->created_at?->toIso8601String(),
                'actor' => ctype_digit($actorId) ? ($users->get((int) $actorId)?->name ?? $actorId) : ($actorId !== '' ? $actorId : null),
                'client' => $clients[$revision->note_id.'|'.$revision->content_hash] ?? null,
            ];
        })->values()->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function outgoingLinks(AuthenticatedSubject $subject, Note $note): array
    {
        return (new OutgoingLinks($this->noteAccess))->for($subject, $note);
    }

    /**
     * OpenAI connector shape: `{results: [{id, title, url}]}` across every
     * workspace the subject reaches, `id` = "<workspace_id>:<path>".
     *
     * @return array{results: list<array{id: string, title: string, url: string}>}
     */
    public function search(AuthenticatedSubject $subject, string $query): array
    {
        $results = [];
        foreach ($this->workspaces($subject) as $workspace) {
            foreach (app(WorkspaceSearch::class)->search($workspace, $query, $subject) as $hit) {
                $results[] = [
                    'id' => $workspace->id.':'.$hit['path'],
                    'title' => (string) ($hit['title'] ?: $hit['path']),
                    'url' => $this->noteUrl($workspace->id, (int) $hit['id']),
                    '_relevance' => (float) ($hit['relevance'] ?? 0),
                ];
            }
        }

        usort($results, static fn (array $a, array $b): int => $b['_relevance'] <=> $a['_relevance']);

        return ['results' => array_map(static function (array $result): array {
            unset($result['_relevance']);

            return $result;
        }, array_slice($results, 0, self::SEARCH_LIMIT))];
    }

    /**
     * OpenAI connector shape: `{id, title, text, url, metadata}` for one note.
     *
     * @return array<string, mixed>|null null when the id is unknown or hidden
     */
    public function fetch(AuthenticatedSubject $subject, string $id): ?array
    {
        if (preg_match('/^(\d+):(.+)$/s', $id, $matches) !== 1) {
            return null;
        }

        $workspaceId = (int) $matches[1];
        $path = $matches[2];
        if (! $this->identityProvider->isAuthorizedForWorkspace($subject, $workspaceId)) {
            return null;
        }

        $workspace = Workspace::query()->find($workspaceId);
        $note = $workspace === null ? null : Note::query()->where('workspace_id', $workspaceId)->where('path', $path)->first();
        if ($note === null || ! $this->noteAccess->canView($subject, $note)) {
            return null;
        }

        try {
            $text = $this->storage->readContents($workspace, $note->path);
        } catch (Throwable) {
            return null;
        }

        return [
            'id' => $workspaceId.':'.$note->path,
            'title' => (string) ($note->title ?: $note->path),
            'text' => $text,
            'url' => $this->noteUrl($workspaceId, $note->id),
            'metadata' => [
                'workspace' => $workspace->slug,
                'path' => $note->path,
                'updated_at' => $note->updated_at?->toIso8601String(),
                'sha256' => hash('sha256', $text),
            ],
        ];
    }

    public function noteUrl(int $workspaceId, int $noteId): string
    {
        return rtrim((string) config('app.url'), '/').'/?workspace='.$workspaceId.'&note='.$noteId;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function projects(AuthenticatedSubject $subject, Workspace $workspace): array
    {
        return $this->noteAccess->scopeVisible(Note::query(), $subject, $workspace->id)
            ->where('notes.path', 'like', self::PROJECT_STATE_PATTERN)
            ->orderBy('notes.path')
            ->limit(100)
            ->get(['notes.id', 'notes.path', 'notes.title', 'notes.frontmatter', 'notes.updated_at'])
            ->map(static function (Note $note): array {
                $frontmatter = is_array($note->frontmatter) ? $note->frontmatter : [];
                $project = [
                    'project' => explode('/', $note->path)[1] ?? $note->path,
                    'path' => $note->path,
                    'title' => $note->title,
                ];
                foreach (self::PROJECT_FIELDS as $field) {
                    $value = $frontmatter[$field] ?? null;
                    $project[$field] = is_scalar($value) || $value === null ? $value : json_encode($value);
                }

                return $project;
            })
            ->values()
            ->all();
    }

    private function readVisible(AuthenticatedSubject $subject, Workspace $workspace, string $path): ?string
    {
        $note = Note::query()->where('workspace_id', $workspace->id)->where('path', $path)->first();
        if ($note === null || ! $this->noteAccess->canView($subject, $note)) {
            return null;
        }

        try {
            return $this->storage->readContents($workspace, $path);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Maps "<note_id>|<sha256>" to the MCP client that wrote that exact content.
     *
     * @param Collection<int, NoteRevision> $revisions
     * @return array<string, string>
     */
    private function clientsByWrite(Collection $revisions): array
    {
        $map = [];
        AuditLog::query()
            ->where('event', 'mcp.note_written')
            ->whereIn('note_id', $revisions->pluck('note_id')->unique())
            ->orderBy('id')
            ->get(['note_id', 'metadata'])
            ->each(static function (AuditLog $log) use (&$map): void {
                $sha = $log->metadata['sha256_after'] ?? null;
                $client = $log->metadata['client_slug'] ?? null;
                if (is_string($sha) && is_string($client) && $client !== '') {
                    $map[$log->note_id.'|'.$sha] = $client;
                }
            });

        return $map;
    }

    /**
     * @return list<Workspace>
     */
    private function workspaces(AuthenticatedSubject $subject): array
    {
        return Workspace::query()->orderBy('id')->get()
            ->filter(fn (Workspace $workspace): bool => $this->identityProvider->isAuthorizedForWorkspace($subject, $workspace->id))
            ->values()
            ->all();
    }

    /**
     * Drops recent changes, then projects, then shortens the README and the
     * instructions until the JSON fits CONTEXT_MAX_BYTES.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function fitToBudget(array $context): array
    {
        $size = static fn (array $value): int => strlen((string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        while ($size($context) > self::CONTEXT_MAX_BYTES && $context['recent_changes'] !== []) {
            array_pop($context['recent_changes']);
            $context['truncated'] = true;
        }
        while ($size($context) > self::CONTEXT_MAX_BYTES && $context['projects'] !== []) {
            array_pop($context['projects']);
            $context['truncated'] = true;
        }
        foreach (['readme', 'instructions'] as $field) {
            while ($size($context) > self::CONTEXT_MAX_BYTES && is_string($context[$field]) && $context[$field] !== '') {
                $over = $size($context) - self::CONTEXT_MAX_BYTES;
                $keep = max(0, strlen($context[$field]) - $over - 64);
                $context[$field] = $keep === 0 ? '' : mb_strcut($context[$field], 0, $keep, 'UTF-8').'…';
                $context['truncated'] = true;
            }
        }

        return $context;
    }
}
