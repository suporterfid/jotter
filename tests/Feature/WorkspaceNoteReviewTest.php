<?php

namespace Tests\Feature;

use App\Domain\Auth\AuthenticatedSubject;
use App\Domain\Audit\AuditEvent;
use App\Domain\Review\NoteReviewState;
use App\Domain\Review\NoteReviewWorkflowService;
use App\Domain\Vault\VaultStorage;
use App\Models\AuditLog;
use App\Models\Membership;
use App\Models\NoteReviewWorkflow;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\TestCase;

final class WorkspaceNoteReviewTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $vaultRoots = [];

    protected function tearDown(): void
    {
        foreach ($this->vaultRoots as $root) {
            $this->deleteTree($root);
        }

        parent::tearDown();
    }

    public function test_note_has_one_review_workflow_projection_with_a_valid_state(): void
    {
        [$workspace, $note] = $this->workspaceNote('projection');

        $workflow = NoteReviewWorkflow::query()->create([
            'note_id' => $note->id,
            'state' => NoteReviewState::DRAFT,
        ]);

        $this->assertSame($note->id, $workflow->note->id);
        $this->assertSame(NoteReviewState::DRAFT, $workflow->state);
        $this->assertSame($workflow->id, $note->fresh()->reviewWorkflow->id);
        $this->assertDatabaseHas('note_review_workflows', [
            'note_id' => $note->id,
            'state' => NoteReviewState::DRAFT->value,
        ]);
        $this->assertSame($workspace->id, $note->workspace_id);
    }

    public function test_review_workflow_allows_reviewer_and_approval_hash_metadata(): void
    {
        [$workspace, $note, $editor] = $this->workspaceNote('metadata');
        $reviewer = User::factory()->create();
        $this->membership($reviewer, $workspace, 'viewer');

        $workflow = NoteReviewWorkflow::query()->create([
            'note_id' => $note->id,
            'reviewer_id' => $reviewer->id,
            'state' => NoteReviewState::APPROVED,
            'submitted_by_id' => $editor->id,
            'submitted_at' => now(),
            'approved_content_hash' => str_repeat('a', 64),
            'approved_at' => now(),
        ]);

        $workflow->refresh();
        $this->assertSame($reviewer->id, $workflow->reviewer_id);
        $this->assertSame($editor->id, $workflow->submitted_by_id);
        $this->assertSame(str_repeat('a', 64), $workflow->approved_content_hash);
        $this->assertNotNull($workflow->submitted_at);
        $this->assertNotNull($workflow->approved_at);
    }

    public function test_editor_can_submit_note_for_review_and_audit_the_transition(): void
    {
        [$workspace, $note, $editor] = $this->workspaceNote('submit');

        $workflow = app(NoteReviewWorkflowService::class)->submit($note, $this->subject($editor));

        $this->assertSame(NoteReviewState::IN_REVIEW, $workflow->state);
        $this->assertSame($editor->id, $workflow->submitted_by_id);
        $this->assertNotNull($workflow->submitted_at);
        $this->assertSame($workspace->id, AuditLog::query()->where('event', AuditEvent::NOTE_REVIEW_SUBMITTED->value)->value('workspace_id'));
    }

    public function test_assigned_reviewer_can_approve_the_submitted_content(): void
    {
        [$workspace, $note, $editor] = $this->workspaceNote('approve');
        $reviewer = User::factory()->create();
        $this->membership($reviewer, $workspace, 'viewer');
        NoteReviewWorkflow::query()->create([
            'note_id' => $note->id,
            'reviewer_id' => $reviewer->id,
        ]);

        $service = app(NoteReviewWorkflowService::class);
        $service->submit($note, $this->subject($editor));
        $workflow = $service->approve($note, $this->subject($reviewer));

        $this->assertSame(NoteReviewState::APPROVED, $workflow->state);
        $this->assertSame($note->content_hash, $workflow->approved_content_hash);
        $this->assertNotNull($workflow->approved_at);
        $this->assertDatabaseHas('audit_log', [
            'workspace_id' => $workspace->id,
            'note_id' => $note->id,
            'event' => AuditEvent::NOTE_REVIEW_APPROVED->value,
        ]);
    }

    public function test_reviewer_can_request_changes_with_a_reason(): void
    {
        [$workspace, $note, $editor] = $this->workspaceNote('changes');
        $reviewer = User::factory()->create();
        $this->membership($reviewer, $workspace, 'viewer');
        NoteReviewWorkflow::query()->create([
            'note_id' => $note->id,
            'reviewer_id' => $reviewer->id,
        ]);

        $service = app(NoteReviewWorkflowService::class);
        $service->submit($note, $this->subject($editor));
        $workflow = $service->requestChanges($note, $this->subject($reviewer), 'Please add a source.');

        $this->assertSame(NoteReviewState::CHANGES_REQUESTED, $workflow->state);
        $this->assertDatabaseHas('audit_log', [
            'note_id' => $note->id,
            'event' => AuditEvent::NOTE_REVIEW_CHANGES_REQUESTED->value,
        ]);
        $audit = AuditLog::query()->where('note_id', $note->id)->where('event', AuditEvent::NOTE_REVIEW_CHANGES_REQUESTED->value)->first();
        $this->assertSame('Please add a source.', $audit?->metadata['reason'] ?? null);
    }

    public function test_editor_cannot_approve_own_submission(): void
    {
        [, $note, $editor] = $this->workspaceNote('self-approval');
        NoteReviewWorkflow::query()->create([
            'note_id' => $note->id,
            'reviewer_id' => $editor->id,
        ]);

        $service = app(NoteReviewWorkflowService::class);
        $service->submit($note, $this->subject($editor));

        $this->expectException(AuthorizationException::class);
        $service->approve($note, $this->subject($editor));
    }

    public function test_approved_content_becomes_stale_when_note_hash_changes(): void
    {
        [, $note, $editor] = $this->workspaceNote('stale');
        NoteReviewWorkflow::query()->create([
            'note_id' => $note->id,
            'reviewer_id' => $editor->id,
            'state' => NoteReviewState::APPROVED,
            'approved_content_hash' => $note->content_hash,
            'approved_at' => now(),
        ]);
        $note->forceFill(['content_hash' => str_repeat('b', 64)])->save();

        $review = app(NoteReviewWorkflowService::class)->get($note->fresh(), $this->subject($editor));

        $this->assertSame(NoteReviewState::DRAFT->value, $review['state']);
        $this->assertTrue($review['stale']);
    }

    /** @return array{0: Workspace, 1: \App\Models\Note, 2: User} */
    private function workspaceNote(string $suffix): array
    {
        $tenant = Tenant::create([
            'slug' => 'review-'.$suffix.'-'.uniqid(),
            'name' => 'Review '.$suffix,
        ]);
        $workspace = Workspace::create([
            'tenant_id' => $tenant->id,
            'slug' => 'review-workspace-'.$suffix.'-'.uniqid(),
            'name' => 'Review Workspace '.$suffix,
            'vault_path' => sys_get_temp_dir().'/jotter-review-'.uniqid('', true),
        ]);
        mkdir($workspace->vault_path, 0755, true);
        $this->vaultRoots[] = $workspace->vault_path;

        $editor = User::factory()->create();
        $this->membership($editor, $workspace, 'editor');
        $note = app(VaultStorage::class)->write($workspace, 'note.md', "# Note\n");

        return [$workspace, $note, $editor];
    }

    private function membership(User $user, Workspace $workspace, string $role): void
    {
        Membership::create([
            'subject_id' => (string) $user->id,
            'tenant_id' => $workspace->tenant_id,
            'workspace_id' => $workspace->id,
            'role' => $role,
        ]);
    }

    private function subject(User $user, bool $isAdmin = false): AuthenticatedSubject
    {
        return new AuthenticatedSubject((string) $user->id, $user->email, $user->name, $isAdmin, $user);
    }

    private function deleteTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $child = $path.DIRECTORY_SEPARATOR.$item;
            is_dir($child) ? $this->deleteTree($child) : @unlink($child);
        }

        @rmdir($path);
    }
}
