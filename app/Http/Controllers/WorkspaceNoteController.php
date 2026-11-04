<?php

namespace App\Http\Controllers;

use App\Domain\Auth\AuthenticatedSubject;
use App\Domain\Auth\Contracts\IdentityProvider;
use App\Domain\Auth\NoteAccess;
use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Events\WorkspaceEventEmitter;
use App\Domain\Vault\Exceptions\PathTraversalRejected;
use App\Domain\Vault\Exceptions\VaultNoteNotFound;
use App\Domain\Vault\NoteTrash;
use App\Domain\Vault\VaultStorage;
use App\Domain\Review\NoteReviewWorkflowService;
use App\Models\Note;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

final class WorkspaceNoteController extends Controller
{
    public function __construct(
        private readonly IdentityProvider $identityProvider,
        private readonly NoteAccess $noteAccess,
        private readonly WorkspaceEventEmitter $eventEmitter,
        private readonly AuditRecorder $auditRecorder,
        private readonly NoteReviewWorkflowService $reviewWorkflowService,
    ) {}

    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        $subject = $this->subject($request);
        if (! $subject) {
            return response()->json(['message' => __('messages.unauthenticated')], 401);
        }

        return response()->json([
            'data' => $this->noteAccess->scopeVisible($workspace->notes()->getQuery(), $subject, $workspace->id)
                ->orderBy('path')
                ->get()
                ->map(fn (Note $note): array => $this->metadata($note, $subject))
                ->all(),
        ]);
    }

    public function store(Request $request, Workspace $workspace, VaultStorage $storage): JsonResponse
    {
        $subject = $this->subject($request);
        $validated = $request->validate([
            'path' => ['required', 'string', 'max:700'],
            'content' => ['present', 'string'],
        ]);

        try {
            $note = $storage->write($workspace, $validated['path'], $validated['content'], $subject->subjectId);
        } catch (PathTraversalRejected $exception) {
            throw ValidationException::withMessages(['path' => [$exception->getMessage()]]);
        }

        return response()->json(['data' => $this->metadata($note, $subject)], 201);
    }

    public function show(Request $request, Workspace $workspace, int $note, VaultStorage $storage, \App\Domain\Vault\MarkdownServerRenderer $renderer): JsonResponse
    {
        $subject = $this->subject($request);
        $note = $this->scopedNote($workspace, $note);
        $this->noteAccess->assertView($subject, $note);

        try {
            $content = $storage->readContents($workspace, $note->path);
        } catch (VaultNoteNotFound) {
            abort(404);
        }

        if (config('jotter.analytics.record_reads', false)) {
            $this->auditRecorder->record(
                AuditEvent::NOTE_VIEWED,
                tenantId: $workspace->tenant_id,
                workspaceId: $workspace->id,
                actorId: $subject->subjectId,
                metadata: ['source' => 'workspace_note_show'],
                noteId: $note->id,
            );
        }

        $backlinks = $note->incomingLinks()
            ->with('sourceNote')
            ->get()
            ->filter(fn ($link) => $link->sourceNote !== null && $this->noteAccess->canView($subject, $link->sourceNote))
            ->map(fn ($link) => [
                'id' => $link->sourceNote->id,
                'path' => $link->sourceNote->path,
                'title' => $link->sourceNote->title,
                'target_ref' => $link->target_ref,
            ])
            ->unique('id')
            ->values()
            ->all();

        return response()->json([
            'data' => array_merge($this->metadata($note, $subject), [
                // Hash of the bytes just read from disk: the index can lag behind
                // out-of-band edits (Obsidian/WebDAV) until the next reindex.
                'content_hash' => hash('sha256', $content),
                'content' => $content,
                'html_rendered' => $renderer->render($content),
                'backlinks' => $backlinks,
            ]),
        ]);
    }

    public function update(Request $request, Workspace $workspace, int $note, VaultStorage $storage): JsonResponse
    {
        $validated = $request->validate([
            'content' => ['present', 'string'],
            'base_sha256' => ['sometimes', 'nullable', 'string', 'regex:/^[0-9a-f]{64}$/'],
        ]);
        $note = $this->scopedNote($workspace, $note);
        $subject = $this->subject($request);
        $this->noteAccess->assertEdit($subject, $note);
        $baseSha256 = $validated['base_sha256'] ?? null;

        // Optimistic concurrency: with base_sha256 the write happens only when the
        // file on disk still has that hash, under the same per-note lock the MCP
        // write tools take. Without it the request overwrites, as before.
        $write = function () use ($workspace, $note, $validated, $subject, $storage, $baseSha256): Note|JsonResponse {
            if ($baseSha256 !== null) {
                try {
                    $current = hash('sha256', $storage->readContents($workspace, $note->path));
                } catch (VaultNoteNotFound) {
                    abort(404);
                }

                if (! hash_equals($current, $baseSha256)) {
                    return response()->json([
                        'message' => __('messages.note_changed_conflict'),
                        'current_sha256' => $current,
                    ], 409);
                }
            }

            return $storage->write($workspace, $note->path, $validated['content'], $subject->subjectId);
        };

        try {
            $outcome = $baseSha256 === null
                ? $write()
                : Cache::lock('note:'.$workspace->id.':'.sha1($note->path), 10)->block(5, $write);
        } catch (LockTimeoutException) {
            return response()->json(['message' => __('messages.note_locked')], 423);
        }

        if ($outcome instanceof JsonResponse) {
            return $outcome;
        }

        $this->eventEmitter->ensureAutoWatch($outcome, $subject->user?->id);
        $this->eventEmitter->emitNoteEdited($outcome, $subject);

        return response()->json(['data' => $this->metadata($outcome, $subject)]);
    }

    public function destroy(Request $request, Workspace $workspace, int $note, NoteTrash $trash): JsonResponse
    {
        $note = $this->scopedNote($workspace, $note);
        $subject = $this->subject($request);
        $this->noteAccess->assertEdit($subject, $note);

        try {
            $deletedNote = $trash->trash($workspace, $note);
        } catch (VaultNoteNotFound) {
            abort(404);
        }

        $this->eventEmitter->emitNoteDeleted($deletedNote, $subject);

        return response()->json(status: 204);
    }

    public function move(Request $request, Workspace $workspace, int $note, VaultStorage $storage): JsonResponse
    {
        $validated = $request->validate([
            'new_path' => ['required', 'string', 'max:700'],
        ]);

        $note = $this->scopedNote($workspace, $note);
        $subject = $this->subject($request);
        $this->noteAccess->assertEdit($subject, $note);
        $oldPath = $note->path;

        try {
            $movedNote = $storage->move($workspace, $note->path, $validated['new_path']);
        } catch (PathTraversalRejected $exception) {
            throw ValidationException::withMessages(['new_path' => [$exception->getMessage()]]);
        }

        $this->eventEmitter->transferWatchers($note, $movedNote);
        $this->eventEmitter->emitNoteMoved($movedNote, $subject, $oldPath);

        return response()->json([
            'data' => $this->metadata($movedNote, $subject),
        ]);
    }

    public function outgoingLinks(Request $request, Workspace $workspace, int $note): JsonResponse
    {
        $subject = $this->subject($request);
        $note = $this->scopedNote($workspace, $note);
        $this->noteAccess->assertView($subject, $note);

        return response()->json(['data' => (new \App\Domain\Links\OutgoingLinks($this->noteAccess))->for($subject, $note)]);
    }

    private function scopedNote(Workspace $workspace, int $noteId): Note
    {
        return $workspace->notes()->findOrFail($noteId);
    }

    private function subject(Request $request): AuthenticatedSubject
    {
        return $request->attributes->get('authenticated_subject')
            ?? $this->identityProvider->resolveIdentity($request);
    }

    /**
     * @return array{id: int, path: string, title: string, frontmatter: array<string, mixed>|null, updated_at: string}
     */
    private function metadata(Note $note, ?AuthenticatedSubject $subject = null): array
    {
        $note->loadMissing('properties');
        $properties = $note->properties->map(fn ($p) => [
            'name' => $p->name,
            'type' => $p->type instanceof \App\Domain\Vault\NotePropertyType ? $p->type->value : (string) $p->type,
            'value' => match ($p->type) {
                \App\Domain\Vault\NotePropertyType::BOOLEAN => $p->value_boolean,
                \App\Domain\Vault\NotePropertyType::NUMERIC => $p->value_numeric,
                \App\Domain\Vault\NotePropertyType::DATETIME => $p->value_datetime?->toIso8601String(),
                \App\Domain\Vault\NotePropertyType::LIST, \App\Domain\Vault\NotePropertyType::JSON => $p->value_json,
                default => $p->value_string,
            },
        ])->all();

        return [
            'id' => $note->id,
            'path' => $note->path,
            'title' => $note->title,
            'frontmatter' => $note->frontmatter,
            'properties' => $properties,
            'sort_position' => $note->sort_position,
            'content_hash' => $note->content_hash,
            'updated_at' => $note->updated_at->toISOString(),
            'watching' => $subject?->user === null ? false : $this->eventEmitter->isWatching($note, $subject->user->id),
            'access' => $subject === null ? null : [
                'restricted' => $this->noteAccess->isRestricted($note),
                'can_view' => $this->noteAccess->canView($subject, $note),
                'can_edit' => $this->noteAccess->canEdit($subject, $note),
            ],
            'review' => $subject === null ? null : $this->reviewWorkflowService->get($note, $subject),
        ];
    }
}
