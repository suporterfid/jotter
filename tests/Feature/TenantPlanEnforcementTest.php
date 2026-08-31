<?php

namespace Tests\Feature;

use App\Domain\Vault\VaultStorage;
use App\Models\AuditLog;
use App\Models\Membership;
use App\Models\Note;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Hosted-mode plan gate. `self_hosted` (the default) must change nothing; an
 * expired trial, past_due, or read_only tenant keeps reading, searching,
 * exporting, and logging in while writes answer 402.
 */
final class TenantPlanEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($this->admin);
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array{0: Tenant, 1: Workspace, 2: Note}
     */
    private function fixture(array $plan = []): array
    {
        $tenant = Tenant::create(array_merge(['slug' => 'plan-'.uniqid(), 'name' => 'Plan'], $plan));
        $vaultPath = storage_path('app/vaults/plan_'.uniqid());
        @mkdir($vaultPath, 0755, true);
        $workspace = Workspace::create([
            'tenant_id' => $tenant->id,
            'slug' => 'plan-'.uniqid(),
            'name' => 'Plan workspace',
            'vault_path' => $vaultPath,
        ]);
        $note = app(VaultStorage::class)->write($workspace, 'readme.md', "# Readme\n");

        return [$tenant, $workspace, $note];
    }

    public function test_default_tenant_is_self_hosted_and_writes_are_unaffected(): void
    {
        [$tenant, $workspace, $note] = $this->fixture();

        $this->assertSame('self_hosted', $tenant->fresh()->plan_status);
        $this->putJson("/api/workspaces/{$workspace->id}/notes/{$note->id}", ['content' => '# Changed'])->assertOk();
        $this->getJson('/api/tenants')->assertOk()->assertJsonPath('data.0.plan.status', 'self_hosted')->assertJsonPath('data.0.plan.read_only', false);
        $this->assertSame(0, AuditLog::query()->where('event', 'plan.write_blocked')->count());
    }

    public function test_active_trial_allows_writes_and_reports_days_left(): void
    {
        [, $workspace, $note] = $this->fixture(['plan_status' => 'trial', 'trial_ends_at' => now()->addDays(7)->addMinute()]);

        $this->putJson("/api/workspaces/{$workspace->id}/notes/{$note->id}", ['content' => '# Trial'])->assertOk();

        $plan = $this->getJson('/api/tenants')->assertOk()->json('data.0.plan');
        $this->assertSame('trial', $plan['status']);
        $this->assertSame(8, $plan['trial_days_left']);
        $this->assertFalse($plan['read_only']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function blockedPlans(): iterable
    {
        yield 'expired trial' => [['plan_status' => 'trial', 'trial_ends_at' => '2026-01-01 00:00:00']];
        yield 'read_only' => [['plan_status' => 'read_only']];
        yield 'past_due' => [['plan_status' => 'past_due']];
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    #[DataProvider('blockedPlans')]
    public function test_blocked_plan_rejects_writes_with_402_but_keeps_reads(array $plan): void
    {
        [$tenant, $workspace, $note] = $this->fixture($plan);

        // API writes behind workspace.write
        $this->putJson("/api/workspaces/{$workspace->id}/notes/{$note->id}", ['content' => '# Nope'])
            ->assertStatus(402)
            ->assertJsonPath('plan_status', $plan['plan_status'])
            ->assertJsonPath('message', __('messages.plan_read_only'));
        $this->postJson("/api/workspaces/{$workspace->id}/notes", ['path' => 'new.md', 'content' => '# New'])->assertStatus(402);
        $this->deleteJson("/api/workspaces/{$workspace->id}/notes/{$note->id}")->assertStatus(402);

        // Import (workspace.write) and WebDAV write methods
        $this->postJson("/api/workspaces/{$workspace->id}/import")->assertStatus(402);
        $this->call('PUT', "/api/webdav/{$workspace->id}/blocked.md", [], [], [], [], "# Blocked\n")->assertStatus(402);
        $this->call('MKCOL', "/api/webdav/{$workspace->id}/folder")->assertStatus(402);
        $this->call('DELETE', "/api/webdav/{$workspace->id}/readme.md")->assertStatus(402);

        // Reads, search, export, WebDAV reads, and the plan payload stay available
        $this->getJson("/api/workspaces/{$workspace->id}/notes")->assertOk();
        $this->getJson("/api/workspaces/{$workspace->id}/notes/{$note->id}")->assertOk();
        $this->getJson("/api/workspaces/{$workspace->id}/search?q=Readme")->assertOk();
        $this->get("/api/workspaces/{$workspace->id}/export")->assertOk();
        $this->postJson("/api/workspaces/{$workspace->id}/pdf-exports")->assertStatus(202);
        $this->call('PROPFIND', "/api/webdav/{$workspace->id}/readme.md")->assertStatus(207);
        $this->getJson('/api/tenants')->assertOk()->assertJsonPath('data.0.plan.read_only', true);
        $this->getJson('/api/auth/me')->assertOk();

        $this->assertSame("# Readme\n", file_get_contents($workspace->vault_path.'/readme.md'));
        $this->assertGreaterThanOrEqual(1, AuditLog::query()->where('event', 'plan.write_blocked')->where('tenant_id', $tenant->id)->count());
    }

    public function test_login_still_works_for_a_read_only_tenant(): void
    {
        $this->fixture(['plan_status' => 'read_only']);
        $user = User::factory()->create(['password' => bcrypt('password12345')]);

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password12345'])->assertOk();
    }

    public function test_seat_limit_blocks_new_members_but_not_existing_ones(): void
    {
        [$tenant, $workspace] = $this->fixture(['plan_status' => 'active', 'plan_seats' => 2]);
        Membership::create(['tenant_id' => $tenant->id, 'workspace_id' => $workspace->id, 'subject_id' => 'u1', 'role' => 'owner']);
        Membership::create(['tenant_id' => $tenant->id, 'workspace_id' => $workspace->id, 'subject_id' => 'u2', 'role' => 'editor']);

        $this->postJson("/api/admin/workspaces/{$workspace->id}/members", ['subject_id' => 'u3', 'role' => 'viewer'])
            ->assertStatus(402)
            ->assertJsonPath('plan_seats', 2)
            ->assertJsonPath('message', __('messages.plan_seat_limit_reached', ['seats' => 2]));

        // Changing the role of an existing subject does not consume a seat.
        $this->postJson("/api/admin/workspaces/{$workspace->id}/members", ['subject_id' => 'u2', 'role' => 'viewer'])->assertCreated();

        $this->assertSame(1, AuditLog::query()->where('event', 'plan.seat_limit_reached')->count());
    }

    public function test_seat_limit_is_ignored_for_self_hosted_tenants(): void
    {
        [$tenant, $workspace] = $this->fixture(['plan_seats' => 1]);
        Membership::create(['tenant_id' => $tenant->id, 'workspace_id' => $workspace->id, 'subject_id' => 'u1', 'role' => 'owner']);

        $this->postJson("/api/admin/workspaces/{$workspace->id}/members", ['subject_id' => 'u2', 'role' => 'editor'])->assertCreated();
    }
}
