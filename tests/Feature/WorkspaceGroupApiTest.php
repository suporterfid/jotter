<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceGroupApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_owner_can_manage_groups_and_members(): void
    {
        [$workspace, $owner] = $this->workspaceWithRole('owner');
        $member = User::factory()->create();
        $this->membership($member, $workspace, 'viewer');

        $response = $this->actingAs($owner)->postJson("/api/workspaces/{$workspace->id}/groups", [
            'name' => 'Readers',
            'member_ids' => [$member->id],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Readers')
            ->assertJsonPath('data.members.0.id', $member->id);

        $group = WorkspaceGroup::query()->firstOrFail();
        $this->actingAs($owner)
            ->putJson("/api/workspaces/{$workspace->id}/groups/{$group->id}", ['name' => 'Editors', 'member_ids' => []])
            ->assertOk()
            ->assertJsonPath('data.name', 'Editors')
            ->assertJsonCount(0, 'data.members');

        $this->actingAs($owner)
            ->deleteJson("/api/workspaces/{$workspace->id}/groups/{$group->id}")
            ->assertNoContent();
    }

    public function test_viewer_cannot_manage_groups_or_cross_workspace_members(): void
    {
        [$workspace, $viewer] = $this->workspaceWithRole('viewer');
        $member = User::factory()->create();

        $this->actingAs($viewer)
            ->postJson("/api/workspaces/{$workspace->id}/groups", ['name' => 'Readers', 'member_ids' => [$member->id]])
            ->assertForbidden();

        [$otherWorkspace, $owner] = $this->workspaceWithRole('owner');
        $otherMember = User::factory()->create();
        $this->membership($otherMember, $otherWorkspace, 'viewer');

        $this->actingAs($owner)
            ->postJson("/api/workspaces/{$workspace->id}/groups", ['name' => 'Readers', 'member_ids' => [$otherMember->id]])
            ->assertForbidden();
    }

    /** @return array{0: Workspace, 1: User} */
    private function workspaceWithRole(string $role): array
    {
        $tenant = Tenant::create(['slug' => 'group-api-'.uniqid(), 'name' => 'Group API']);
        $workspace = Workspace::create([
            'tenant_id' => $tenant->id,
            'slug' => 'group-api-workspace-'.uniqid(),
            'name' => 'Group API Workspace',
            'vault_path' => sys_get_temp_dir().'/jotter-group-api-'.uniqid('', true),
        ]);
        mkdir($workspace->vault_path, 0755, true);
        $user = User::factory()->create();
        $this->membership($user, $workspace, $role);

        return [$workspace, $user];
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
}
