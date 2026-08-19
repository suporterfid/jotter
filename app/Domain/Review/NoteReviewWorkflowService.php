<?php

namespace App\Domain\Review;

use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Auth\AuthenticatedSubject;
use App\Domain\Auth\NoteAccess;
use App\Models\Membership;
use App\Models\Note;
use App\Models\NoteReviewWorkflow;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class NoteReviewWorkflowService
{
    public function __construct(
        private readonly NoteAccess $noteAccess,
        private readonly AuditRecorder $auditRecorder,
    ) {}

    /** @return array<string, mixed> */
    public function get(Note $note, AuthenticatedSubject $subject): array
    {
        $this->noteAccess->assertView($subject, $note);
        $workflow = $this->workflow($note);
        $state = $this->effectiveState($workflow, $note);
        $stale = $workflow->state === NoteReviewState::APPROVED && $state !== NoteReviewState::APPROVED;

        return [
            'state' => $state->value,
            'stale' => $stale,
            'reviewer' => $workflow->reviewer ? [
                'id' => $workflow->reviewer->id,
                'name' => $workflow->reviewer->name,
                'email' => $workflow->reviewer->email,
            ] : null,
            'submitted_at' => $workflow->submitted_at?->toIso8601String(),
            'approved_at' => $workflow->approved_at?->toIso8601String(),
            'can_assign' => $this->noteAccess->canManage($subject, $note->workspace_id),
            'can_submit' => $this->canSubmit($note, $subject, $state),
            'can_approve' => $this->canReview($note, $subject, $workflow, $state),
            'can_request_changes' => $this->canReview($note, $subject, $workflow, $state),
        ];
    }

    public function assignReviewer(Note $note, AuthenticatedSubject $actor, ?int $reviewerId): NoteReviewWorkflow
    {
        $this->assertCanManage($note, $actor);
        $note->loadMissing('workspace');

        if ($reviewerId !== null) {
            $reviewer = User::query()->find($reviewerId);
            if ($reviewer === null || ! $reviewer->is_active) {
                throw (new ModelNotFoundException())->setModel(User::class, [$reviewerId]);
            }

            $isMember = Membership::query()
                ->where('tenant_id', $note->workspace->tenant_id)
                ->where('workspace_id', $note->workspace_id)
                ->where('subject_id', (string) $reviewer->id)
                ->exists();

            if (! $isMember) {
                throw new AuthorizationException(__('messages.forbidden'));
            }
        }

        return DB::transaction(function () use ($note, $actor, $reviewerId): NoteReviewWorkflow {
            $workflow = $this->workflow($note);
            $oldReviewerId = $workflow->reviewer_id;
            $workflow->forceFill(['reviewer_id' => $reviewerId])->save();

            $this->record(
                AuditEvent::NOTE_REVIEWER_ASSIGNED,
                $note,
                $actor,
                [
                    'reviewer_id' => $reviewerId,
                    'previous_reviewer_id' => $oldReviewerId,
                ],
            );

            return $workflow->fresh(['reviewer']);
        });
    }

    public function submit(Note $note, AuthenticatedSubject $actor): NoteReviewWorkflow
    {
        $this->noteAccess->assertEdit($actor, $note);

        return DB::transaction(function () use ($note, $actor): NoteReviewWorkflow {
            $workflow = $this->workflow($note);
            $state = $this->effectiveState($workflow, $note);
            $this->assertTransition($state, [NoteReviewState::DRAFT, NoteReviewState::CHANGES_REQUESTED]);

            $workflow->forceFill([
                'state' => NoteReviewState::IN_REVIEW,
                'submitted_by_id' => $actor->user?->id,
                'submitted_at' => now(),
            ])->save();

            $this->recordTransition(AuditEvent::NOTE_REVIEW_SUBMITTED, $note, $actor, $state, NoteReviewState::IN_REVIEW);

            return $workflow->fresh(['reviewer']);
        });
    }

    public function approve(Note $note, AuthenticatedSubject $actor): NoteReviewWorkflow
    {
        return DB::transaction(function () use ($note, $actor): NoteReviewWorkflow {
            $workflow = $this->workflow($note);
            $state = $this->effectiveState($workflow, $note);
            $this->assertCanReview($note, $actor, $workflow, $state);
            $this->assertTransition($state, [NoteReviewState::IN_REVIEW]);

            $workflow->forceFill([
                'state' => NoteReviewState::APPROVED,
                'approved_content_hash' => $note->content_hash,
                'approved_at' => now(),
            ])->save();

            $this->recordTransition(
                AuditEvent::NOTE_REVIEW_APPROVED,
                $note,
                $actor,
                $state,
                NoteReviewState::APPROVED,
                ['content_hash' => $note->content_hash],
            );

            return $workflow->fresh(['reviewer']);
        });
    }

    public function requestChanges(Note $note, AuthenticatedSubject $actor, string $reason): NoteReviewWorkflow
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => __('validation.required', ['attribute' => 'reason'])]);
        }

        return DB::transaction(function () use ($note, $actor, $reason): NoteReviewWorkflow {
            $workflow = $this->workflow($note);
            $state = $this->effectiveState($workflow, $note);
            $this->assertCanReview($note, $actor, $workflow, $state);
            $this->assertTransition($state, [NoteReviewState::IN_REVIEW]);

            $workflow->forceFill(['state' => NoteReviewState::CHANGES_REQUESTED])->save();
            $this->recordTransition(
                AuditEvent::NOTE_REVIEW_CHANGES_REQUESTED,
                $note,
                $actor,
                $state,
                NoteReviewState::CHANGES_REQUESTED,
                ['reason' => $reason],
            );

            return $workflow->fresh(['reviewer']);
        });
    }

    public function effectiveState(NoteReviewWorkflow $workflow, Note $note): NoteReviewState
    {
        if ($workflow->state === NoteReviewState::APPROVED
            && $workflow->approved_content_hash !== $note->content_hash) {
            return NoteReviewState::DRAFT;
        }

        return $workflow->state;
    }

    private function workflow(Note $note): NoteReviewWorkflow
    {
        return $note->reviewWorkflow()->firstOrCreate([], ['state' => NoteReviewState::DRAFT]);
    }

    private function canSubmit(Note $note, AuthenticatedSubject $subject, NoteReviewState $state): bool
    {
        return $this->noteAccess->canEdit($subject, $note)
            && in_array($state, [NoteReviewState::DRAFT, NoteReviewState::CHANGES_REQUESTED], true);
    }

    private function canReview(Note $note, AuthenticatedSubject $subject, NoteReviewWorkflow $workflow, NoteReviewState $state): bool
    {
        if ($state !== NoteReviewState::IN_REVIEW || ! $this->noteAccess->canView($subject, $note)) {
            return false;
        }

        return $this->noteAccess->canManage($subject, $note->workspace_id)
            || $workflow->reviewer_id === $subject->user?->id;
    }

    private function assertCanManage(Note $note, AuthenticatedSubject $actor): void
    {
        if (! $this->noteAccess->canManage($actor, $note->workspace_id)) {
            throw new AuthorizationException(__('messages.forbidden'));
        }
    }

    private function assertCanReview(Note $note, AuthenticatedSubject $actor, NoteReviewWorkflow $workflow, NoteReviewState $state): void
    {
        if (! $this->canReview($note, $actor, $workflow, $state)) {
            throw new AuthorizationException(__('messages.forbidden'));
        }

        if ($workflow->submitted_by_id !== null
            && $workflow->submitted_by_id === $actor->user?->id
            && ! $this->noteAccess->canManage($actor, $note->workspace_id)) {
            throw new AuthorizationException(__('messages.forbidden'));
        }
    }

    /** @param list<NoteReviewState> $allowed */
    private function assertTransition(NoteReviewState $state, array $allowed): void
    {
        if (in_array($state, $allowed, true)) {
            return;
        }

        throw ValidationException::withMessages([
            'state' => __('validation.in', ['attribute' => 'state', 'values' => implode(', ', array_map(static fn (NoteReviewState $value): string => $value->value, $allowed))]),
        ]);
    }

    /** @param array<string, mixed> $metadata */
    private function record(AuditEvent $event, Note $note, AuthenticatedSubject $actor, array $metadata): void
    {
        $note->loadMissing('workspace');
        $this->auditRecorder->record(
            $event,
            $note->workspace->tenant_id,
            $note->workspace_id,
            $actor->subjectId,
            $metadata,
            $note->id,
        );
    }

    /** @param array<string, mixed> $metadata */
    private function recordTransition(
        AuditEvent $event,
        Note $note,
        AuthenticatedSubject $actor,
        NoteReviewState $from,
        NoteReviewState $to,
        array $metadata = [],
    ): void {
        $this->record($event, $note, $actor, array_merge([
            'from_state' => $from->value,
            'to_state' => $to->value,
        ], $metadata));
    }
}
