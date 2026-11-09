<?php

namespace Tests\Feature;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\AuditEvent;
use App\Models\AuditLog;
use App\Models\Identity;
use App\Models\MachineToken;
use App\Models\Membership;
use App\Models\Note;
use App\Models\NoteAclEntry;
use App\Models\NoteComment;
use App\Models\NoteRevision;
use App\Models\NoteWatcher;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * #390 follow-up (LGPD): administrators delete an account; people correct their name.
 */
final class UserDeletionAndProfileTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $joe;
    private Workspace $workspace;
    private Note $note;

    protected function setUp(): void
    {
        parent::setUp();
        config(['jotter.auth_bypass' => false, 'jotter.auth_provider' => 'local']);

        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->joe = User::factory()->create(['name' => 'Joe Doe', 'email' => 'joe@acme.test']);
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);
        $this->workspace = Workspace::create(['tenant_id' => $tenant->id, 'slug' => 'brain', 'name' => 'Brain', 'vault_path' => storage_path('app/vaults/del_'.uniqid())]);
        $this->note = Note::create(['workspace_id' => $this->workspace->id, 'path' => 'a.md', 'title' => 'A', 'content_hash' => str_repeat('a', 64)]);

        Membership::create(['tenant_id' => $tenant->id, 'workspace_id' => $this->workspace->id, 'subject_id' => (string) $this->joe->id, 'role' => 'editor']);
        Membership::create(['tenant_id' => $tenant->id, 'workspace_id' => $this->workspace->id, 'subject_id' => 'sso-uuid-joe', 'role' => 'viewer']);
        Identity::create(['user_id' => $this->joe->id, 'provider' => 'grandpasson', 'subject_id' => 'sso-uuid-joe']);
        MachineToken::create(['tenant_id' => $tenant->id, 'subject_id' => (string) $this->joe->id, 'name' => 'Hermes', 'scopes' => ['mcp:read'], 'token_hash' => MachineToken::hashToken('jt_mkt_joe')]);
        NoteAclEntry::create(['note_id' => $this->note->id, 'principal_type' => 'user', 'principal_id' => $this->joe->id, 'permission' => 'view']);
        NoteComment::create(['workspace_id' => $this->workspace->id, 'note_id' => $this->note->id, 'user_id' => $this->joe->id, 'actor_name' => 'Joe Doe', 'content' => 'Boa ideia']);
        NoteRevision::create(['workspace_id' => $this->workspace->id, 'note_id' => $this->note->id, 'content_hash' => str_repeat('b', 64), 'content' => '# A', 'actor_id' => (string) $this->joe->id]);
        NoteWatcher::query()->create(['note_id' => $this->note->id, 'user_id' => $this->joe->id] + (\Illuminate\Support\Facades\Schema::hasColumn('note_watchers', 'workspace_id') ? ['workspace_id' => $this->workspace->id] : []));
        (new AuditRecorder)->record(event: AuditEvent::AUTH_LOGIN_FAILURE, metadata: ['email' => 'joe@acme.test', 'reason' => 'invalid_credentials']);
        (new AuditRecorder)->record(event: AuditEvent::AUTH_LOGIN_SUCCESS, actorId: (string) $this->joe->id);
    }

    public function test_an_administrator_deletes_the_account_and_everything_that_identifies_the_person(): void
    {
        $response = $this->actingAs($this->admin)->deleteJson("/api/admin/users/{$this->joe->id}")->assertOk();
        $this->assertSame(2, $response->json('data.removed.memberships'), 'memberships by user id and by SSO UUID');

        $this->assertNull(User::query()->find($this->joe->id));
        $this->assertSame(0, Membership::query()->count());
        $this->assertSame(0, MachineToken::query()->count());
        $this->assertSame(0, Identity::query()->count());
        $this->assertSame(0, NoteAclEntry::query()->count());
        $this->assertSame(0, NoteWatcher::query()->count());

        // Workspace content stays, without the person's name.
        $comment = NoteComment::query()->sole();
        $this->assertSame('Boa ideia', $comment->content);
        $this->assertSame('Deleted user', $comment->actor_name);
        $this->assertNull($comment->user_id);
        $this->assertSame('deleted-user', NoteRevision::query()->value('actor_id'));
        $this->assertNotNull(Note::query()->find($this->note->id));

        $this->assertSame(0, AuditLog::query()->where('actor_subject_id', (string) $this->joe->id)->count());
        $this->assertSame(0, AuditLog::query()->where('metadata', 'like', '%joe@acme.test%')->count(), 'e-mail removed from audit metadata');
        $deleted = AuditLog::query()->where('event', 'user.deleted')->sole();
        $this->assertSame((string) $this->admin->id, $deleted->actor_subject_id);
        $this->assertStringNotContainsString('joe@acme.test', (string) json_encode($deleted->metadata));

        $this->flushHeaders();
        $this->withToken('jt_mkt_joe')->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertUnauthorized();
    }

    public function test_self_deletion_the_last_admin_and_non_admins_are_refused(): void
    {
        $this->actingAs($this->admin)->deleteJson("/api/admin/users/{$this->admin->id}")
            ->assertStatus(422)->assertJsonPath('message', __('messages.user_delete_self'));

        $this->assertSame(1, Artisan::call('user:delete', ['email' => $this->admin->email, '--force' => true]));
        $this->assertStringContainsString('last active administrator', Artisan::output());
        $this->assertNotNull($this->admin->fresh());

        $this->actingAs($this->joe)->deleteJson("/api/admin/users/{$this->admin->id}")->assertForbidden();
    }

    public function test_the_cli_deletes_by_email(): void
    {
        $this->assertSame(0, Artisan::call('user:delete', ['email' => 'JOE@acme.test', '--force' => true, '--json' => true]));
        $this->assertTrue(json_decode(Artisan::output(), true)['deleted']);
        $this->assertNull(User::query()->find($this->joe->id));
    }

    public function test_a_person_corrects_their_own_name(): void
    {
        $this->actingAs($this->joe)->putJson('/api/user/profile', ['name' => '  Joana   Doe '])
            ->assertOk()->assertJsonPath('data.name', 'Joana Doe');
        $this->assertSame('Joana Doe', $this->joe->fresh()->name);
        $this->assertSame(1, AuditLog::query()->where('event', 'user.profile_updated')->count());
        $this->getJson('/api/auth/me')->assertJsonPath('data.name', 'Joana Doe');

        $this->putJson('/api/user/profile', ['name' => '   '])->assertStatus(422);
        $this->putJson('/api/user/profile', ['name' => str_repeat('x', 192)])->assertStatus(422);
    }

    public function test_names_from_an_sso_provider_are_changed_there_and_tokens_cannot_edit_profiles(): void
    {
        config(['jotter.auth_provider' => 'oidc']);
        $this->actingAs($this->joe)->putJson('/api/user/profile', ['name' => 'Outro'])->assertStatus(409);
        $this->assertSame('Joe Doe', $this->joe->fresh()->name);

        config(['jotter.auth_provider' => 'local']);
        auth()->logout();
        $this->flushHeaders();
        $this->withToken('jt_mkt_joe')->putJson('/api/user/profile', ['name' => 'Via token'])->assertStatus(403);
        $this->assertSame('Joe Doe', $this->joe->fresh()->name);
    }
}
