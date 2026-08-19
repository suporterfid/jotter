<?php

namespace App\Http\Controllers;

use App\Domain\Auth\AuthenticatedSubject;
use App\Domain\Auth\Contracts\IdentityProvider;
use App\Domain\Review\NoteReviewWorkflowService;
use App\Models\Note;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WorkspaceNoteReviewController extends Controller
{
    public function __construct(
        private readonly IdentityProvider $identityProvider,
        private readonly NoteReviewWorkflowService $workflowService,
    ) {}

    public function show(Request $request, Workspace $workspace, int $note): JsonResponse
    {
        $subject = $this->subject($request);
        if ($subject === null) {
            return response()->json(['message' => __('messages.unauthenticated')], 401);
        }

        return response()->json(['data' => $this->workflowService->get($this->scopedNote($workspace, $note), $subject)]);
    }

    public function assignReviewer(Request $request, Workspace $workspace, int $note): JsonResponse
    {
        $subject = $this->subject($request);
        if ($subject === null) {
            return response()->json(['message' => __('messages.unauthenticated')], 401);
        }

        $validated = $request->validate([
            'reviewer_id' => ['present', 'nullable', 'integer', 'min:1'],
        ]);
        $model = $this->scopedNote($workspace, $note);
        $this->workflowService->assignReviewer($model, $subject, $validated['reviewer_id']);

        return response()->json(['data' => $this->workflowService->get($model->fresh(), $subject)]);
    }

    public function submit(Request $request, Workspace $workspace, int $note): JsonResponse
    {
        return $this->mutate($request, $workspace, $note, function (Note $model, AuthenticatedSubject $subject): void {
            $this->workflowService->submit($model, $subject);
        });
    }

    public function approve(Request $request, Workspace $workspace, int $note): JsonResponse
    {
        return $this->mutate($request, $workspace, $note, function (Note $model, AuthenticatedSubject $subject): void {
            $this->workflowService->approve($model, $subject);
        });
    }

    public function requestChanges(Request $request, Workspace $workspace, int $note): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:5000'],
        ]);

        return $this->mutate($request, $workspace, $note, function (Note $model, AuthenticatedSubject $subject) use ($validated): void {
            $this->workflowService->requestChanges($model, $subject, $validated['reason']);
        });
    }

    /** @param callable(Note, AuthenticatedSubject): void $operation */
    private function mutate(Request $request, Workspace $workspace, int $note, callable $operation): JsonResponse
    {
        $subject = $this->subject($request);
        if ($subject === null) {
            return response()->json(['message' => __('messages.unauthenticated')], 401);
        }

        $model = $this->scopedNote($workspace, $note);
        $operation($model, $subject);

        return response()->json(['data' => $this->workflowService->get($model->fresh(), $subject)]);
    }

    private function scopedNote(Workspace $workspace, int $note): Note
    {
        return $workspace->notes()->findOrFail($note);
    }

    private function subject(Request $request): ?AuthenticatedSubject
    {
        return $request->attributes->get('authenticated_subject')
            ?? $this->identityProvider->resolveIdentity($request);
    }
}
