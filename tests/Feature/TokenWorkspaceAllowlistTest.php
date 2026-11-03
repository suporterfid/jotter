<?php

namespace Tests\Feature;

use App\Domain\Auth\InvalidMachineTokenOptions;
use App\Domain\Auth\MachineTokenIssuer;
use App\Domain\Auth\MachineTokenOptions;
use App\Domain\Vault\VaultStorage;
use App\Models\MachineToken;
use App\Models\Membership;
use App\Models\NoteAclEntry;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Workspace allowlist on credentials (decisions D2/D3): a token restricted to
 * `brain` never sees `brain-restrito`, administrator-owned tokens get no
 * administrator bypass, and legacy tokens keep their current MCP reach.
 */
final class TokenWorkspaceAllowlistTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $vaultRoots = [];
    private Tenant $tenant;
    private Workspace $brain;
    private Workspace $restricted;

    protected function setUp(): void
    {
        parent::setUp();
        config(['jotter.auth_bypass' => false, 'jotter.auth_provider' => 'local']);

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);
        $this->brain = $this->workspace('brain');
        $this->restricted = $this->workspace('brain-restrito');
    }

    protected function tearDown(): void
    {
        foreach ($this->vaultRoots as $root) {
            File::deleteDirectory($root);
        }
        parent::tearDown();
    }

    public function test_token_restricted_to_brain_does_not_list_or_read_the_restricted_workspace(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        foreach ([$this->brain, $this->restricted] as $workspace) {
            Membership::create(['tenant_id' => $this->tenant->id, 'workspace_id' => $workspace->id, 'subject_id' => (string) $user->id, 'role' => 'editor']);
        }
        $plain = $this->issue($user, ['brain'], ['mcp:read', 'api:read']);

        $this->assertSame(['brain'], $this->listWorkspaceSlugs($plain));
        $this->mcp($plain, 'list_notes', ['workspace_id' => $this->restricted->id])->assertStatus(403);
        $this->withToken($plain)->getJson("/api/workspaces/{$this->restricted->id}/notes")->assertStatus(403);
        $this->withToken($plain)->getJson("/api/workspaces/{$this->brain->id}/notes")->assertOk();
    }

    public function test_token_without_allowlist_reaches_only_the_owners_memberships(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        Membership::create(['tenant_id' => $this->tenant->id, 'workspace_id' => $this->brain->id, 'subject_id' => (string) $user->id, 'role' => 'viewer']);
        $plain = $this->issue($user, null, ['mcp:read']);

        $this->assertSame(['brain'], $this->listWorkspaceSlugs($plain));
    }

    public function test_allowlist_cannot_widen_a_non_admin_beyond_memberships(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        Membership::create(['tenant_id' => $this->tenant->id, 'workspace_id' => $this->brain->id, 'subject_id' => (string) $user->id, 'role' => 'viewer']);
        $plain = $this->issue($user, ['brain', 'brain-restrito'], ['mcp:read']);

        $this->assertSame(['brain'], $this->listWorkspaceSlugs($plain));
    }

    public function test_admin_owned_token_has_no_admin_bypass(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $plain = $this->issue($admin, ['brain'], ['mcp:read']);

        $this->assertSame(['brain'], $this->listWorkspaceSlugs($plain));

        $other = User::factory()->create();
        $group = WorkspaceGroup::create(['workspace_id' => $this->brain->id, 'name' => 'Only others']);
        $group->members()->attach($other->id);
        $secret = app(VaultStorage::class)->write($this->brain, 'secret.md', "# Secret\n");
        NoteAclEntry::create(['note_id' => $secret->id, 'principal_type' => 'group', 'principal_id' => $group->id, 'permission' => 'view']);
        app(VaultStorage::class)->write($this->brain, 'open.md', "# Open\n");

        $this->mcp($plain, 'read_note', ['path' => 'open.md'])->assertOk();
        $this->mcp($plain, 'read_note', ['path' => 'secret.md'])->assertStatus(404);
    }

    public function test_admin_owned_token_requires_an_explicit_workspace_list(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        try {
            MachineTokenOptions::fromInput($admin, $this->tenant, ['mcp:read']);
            $this->fail('Expected InvalidMachineTokenOptions.');
        } catch (InvalidMachineTokenOptions $exception) {
            $this->assertArrayHasKey('workspaces', $exception->errors());
        }

        $this->actingAs($admin)
            ->postJson('/api/admin/machine-tokens', ['tenant_id' => $this->tenant->id, 'user_id' => $admin->id, 'name' => 'no list'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['workspaces']);
        $this->assertSame(0, MachineToken::query()->count());
    }

    public function test_workspace_from_another_tenant_is_rejected(): void
    {
        $user = User::factory()->create();
        $otherTenant = Tenant::create(['slug' => 'other', 'name' => 'Other']);
        Workspace::create(['tenant_id' => $otherTenant->id, 'slug' => 'foreign', 'name' => 'Foreign', 'vault_path' => storage_path('app/vaults/foreign_'.uniqid())]);

        $this->expectException(InvalidMachineTokenOptions::class);
        MachineTokenOptions::fromInput($user, $this->tenant, ['mcp:read'], ['foreign']);
    }

    public function test_migration_backfills_legacy_tokens(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create(['is_admin' => false]);
        foreach ([$admin, $user] as $owner) {
            DB::table('machine_tokens')->insert([
                'tenant_id' => $this->tenant->id,
                'subject_id' => (string) $owner->id,
                'name' => 'legacy',
                'token_hash' => hash('sha256', 'legacy-'.$owner->id),
                'scopes' => null,
                'workspace_ids' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Re-running is safe: columns already exist, only the backfill applies.
        (require database_path('migrations/2026_11_03_000001_extend_machine_tokens_table.php'))->up();

        $adminToken = MachineToken::query()->where('subject_id', (string) $admin->id)->sole();
        $userToken = MachineToken::query()->where('subject_id', (string) $user->id)->sole();
        $this->assertSame(['mcp:read'], $adminToken->scopes);
        $this->assertSame(['mcp:read'], $userToken->scopes);
        $this->assertSame([$this->brain->id, $this->restricted->id], $adminToken->workspace_ids);
        $this->assertNull($userToken->workspace_ids);
    }

    /**
     * @param list<string>|null $workspaces
     * @param list<string> $scopes
     */
    private function issue(User $owner, ?array $workspaces, array $scopes): string
    {
        $options = MachineTokenOptions::fromInput($owner, $this->tenant, $scopes, $workspaces);

        return app(MachineTokenIssuer::class)->issue($owner, $this->tenant, 'allowlist', $options)['plain'];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function mcp(string $plain, string $tool, array $arguments = []): TestResponse
    {
        return $this->withToken($plain)->postJson('/api/mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $arguments],
        ]);
    }

    /**
     * @return list<string>
     */
    private function listWorkspaceSlugs(string $plain): array
    {
        $text = (string) $this->mcp($plain, 'list_workspaces')->assertOk()->json('result.content.0.text');

        return array_column(json_decode($text, true, 512, JSON_THROW_ON_ERROR), 'slug');
    }

    private function workspace(string $slug): Workspace
    {
        $root = sys_get_temp_dir().'/jotter-allowlist-'.$slug.'-'.uniqid('', true);
        File::ensureDirectoryExists($root);
        $this->vaultRoots[] = $root;

        return Workspace::create(['tenant_id' => $this->tenant->id, 'slug' => $slug, 'name' => ucfirst($slug), 'vault_path' => $root]);
    }
}
