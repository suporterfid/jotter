<?php

namespace App\Http\Controllers;

use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Vault\MarkdownDocument;
use App\Domain\Vault\NotePropertyType;
use App\Domain\Vault\VaultStorage;
use App\Models\Note;
use App\Models\NoteProperty;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WorkspacePropertyController extends Controller
{
    public function index(Workspace $workspace): JsonResponse
    {
        $properties = NoteProperty::query()
            ->whereHas('note', fn ($q) => $q->where('workspace_id', $workspace->id))
            ->select(['name', 'type'])
            ->distinct()
            ->orderBy('name')
            ->get()
            ->map(fn ($p) => [
                'name' => $p->name,
                'type' => $p->type instanceof NotePropertyType ? $p->type->value : (string) $p->type,
            ])
            ->values()
            ->all();

        return response()->json(['data' => $properties]);
    }

    public function setProperty(Request $request, Workspace $workspace, Note $note, VaultStorage $storage, AuditRecorder $auditRecorder): JsonResponse
    {
        $this->ensureScopedNote($workspace, $note);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'value' => ['present'],
        ]);

        $content = $storage->readContents($workspace, $note->path);
        $fallbackTitle = pathinfo($note->path, PATHINFO_FILENAME);
        $parsed = MarkdownDocument::parse($content, $fallbackTitle);

        $frontmatter = $parsed->frontmatter;
        $frontmatter[$validated['name']] = $validated['value'];

        $newContent = MarkdownDocument::compose($frontmatter, $parsed->body);
        $updatedNote = $storage->write($workspace, $note->path, $newContent);

        $subject = $request->attributes->get('authenticated_subject');
        $auditRecorder->record(
            event: AuditEvent::NOTE_UPDATED,
            tenantId: $workspace->tenant_id,
            workspaceId: $workspace->id,
            actorId: $subject?->subjectId,
            metadata: ['action' => 'property_set', 'name' => $validated['name'], 'value' => $validated['value']],
            noteId: $note->id
        );

        return response()->json(['data' => $this->metadata($updatedNote)]);
    }

    public function deleteProperty(Workspace $workspace, Note $note, string $key, VaultStorage $storage): JsonResponse
    {
        $this->ensureScopedNote($workspace, $note);

        $content = $storage->readContents($workspace, $note->path);
        $fallbackTitle = pathinfo($note->path, PATHINFO_FILENAME);
        $parsed = MarkdownDocument::parse($content, $fallbackTitle);

        $frontmatter = $parsed->frontmatter;
        unset($frontmatter[$key]);

        $newContent = MarkdownDocument::compose($frontmatter, $parsed->body);
        $updatedNote = $storage->write($workspace, $note->path, $newContent);

        return response()->json(['data' => $this->metadata($updatedNote)]);
    }

    private function ensureScopedNote(Workspace $workspace, Note $note): void
    {
        if ($note->workspace_id !== $workspace->id) {
            abort(404);
        }
    }

    private function metadata(Note $note): array
    {
        $note->loadMissing('properties');
        $properties = $note->properties->map(fn ($p) => [
            'name' => $p->name,
            'type' => $p->type instanceof NotePropertyType ? $p->type->value : (string) $p->type,
            'value' => match ($p->type) {
                NotePropertyType::BOOLEAN => $p->value_boolean,
                NotePropertyType::NUMERIC => $p->value_numeric,
                NotePropertyType::DATETIME => $p->value_datetime?->toIso8601String(),
                NotePropertyType::LIST, NotePropertyType::JSON => $p->value_json,
                default => $p->value_string,
            },
        ])->all();

        return [
            'id' => $note->id,
            'path' => $note->path,
            'title' => $note->title,
            'frontmatter' => $note->frontmatter,
            'properties' => $properties,
            'updated_at' => $note->updated_at->toISOString(),
        ];
    }
}
