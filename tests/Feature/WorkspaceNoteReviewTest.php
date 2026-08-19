<?php

namespace Tests\Feature;

use App\Domain\Review\NoteReviewState;
use App\Domain\Vault\VaultStorage;
use App\Models\Membership;
use App\Models\NoteReviewWorkflow;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
