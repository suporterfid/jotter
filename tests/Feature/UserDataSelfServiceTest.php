<?php

namespace Tests\Feature;

use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Auth\MachineTokenIssuer;
use App\Domain\Auth\MachineTokenOptions;
use App\Domain\Vault\VaultStorage;
use App\Mail\DeletionRequestedEmail;
use App\Models\AuditLog;
use App\Models\Membership;
use App\Models\NoteComment;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * LGPD art. 18 self-service: "Your profile → Your data" downloads what Jotter
 * keeps about the person and records a deletion request for an administrator.
 */
final class UserDataSelfServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $vault;
    private Tenant $tenant;
    private Workspace $workspace;
    private User $user;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['jotter.auth_bypass' => false, 'jotter.auth_provider' => 'local']);
        Mail::fake();

        $this->vault = sys_get_temp_dir().'/jotter-my-data-'.uniqid('', true);
        File::ensureDirectoryExists($this->vault);
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);
        $this->workspace = Workspace::create(['tenant_id' => $this->tenant->id, 'slug' => 'wiki', 'name' => 'Wiki', 'vault_path' => $this->vault]);
        $this->admin = User::factory()->create(['is_admin' => true, 'email' => 'admin@acme.test']);
        $this->user = User::factory()->create(['is_admin' => false, 'name' => 'Maria Souza', 'email' => 'maria@acme.test']);
        Membership::create(['tenant_id' => $this->tenant->id, 'workspace_id' => $this->workspace->id, 'subject_id' => (string) $this->user->id, 'role' => 'editor']);
        RateLimiter::clear('user-data-export:'.$this->user->id);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->vault);
        parent::tearDown();
    }

    public function test_the_export_holds_the_persons_data_and_no_secrets(): void
    {
        $note = app(VaultStorage::class)->write($this->workspace, 'ideias.md', "# Ideias\n\nTexto da nota.\n");
        NoteComment::query()->create(['workspace_id' => $this->workspace->id, 'note_id' => $note->id, 'user_id' => $this->user->id, 'actor_name' => 'Maria Souza', 'content' => 'Meu comentário']);
        $options = MachineTokenOptions::fromInput($this->user, $this->tenant, ['api:read'], [$this->workspace->id], []);
        app(MachineTokenIssuer::class)->issue($this->user, $this->tenant, 'Meu token', $options);
        (new AuditRecorder)->record(event: AuditEvent::AUTH_LOGIN_SUCCESS, actorId: (string) $this->user->id);

        $response = $this->actingAs($this->user)->get('/api/user/data-export');

        $response->assertOk();
        $this->assertStringContainsString('attachment; filename="my-data-', (string) $response->headers->get('Content-Disposition'));
        $data = $response->json();
        $this->assertSame('jotter.user-data/1', $data['format']);
        $this->assertSame('maria@acme.test', $data['account']['email']);
        $this->assertSame('wiki', $data['memberships'][0]['workspace']);
        $this->assertSame('editor', $data['memberships'][0]['role']);
        $this->assertSame('Meu comentário', $data['comments'][0]['content']);
        $this->assertSame('ideias.md', $data['comments'][0]['note']);
        $this->assertSame('Meu token', $data['access_tokens'][0]['name']);
        $this->assertSame(['api:read'], $data['access_tokens'][0]['scopes']);
        $this->assertContains('auth.login_success', array_column($data['activity_log'], 'event'));

        $body = (string) $response->getContent();
        $this->assertStringNotContainsString('token_hash', $body);
        $this->assertStringNotContainsString('password', $body);
        $this->assertStringNotContainsString('Texto da nota', $body, 'note bodies belong to the workspace');
        $this->assertDatabaseHas('audit_log', ['event' => 'user.data_exported', 'actor_subject_id' => (string) $this->user->id]);
    }

    public function test_the_export_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($this->user)->get('/api/user/data-export')->assertOk();
        }
        $this->actingAs($this->user)->get('/api/user/data-export')->assertStatus(429);
    }

    public function test_only_a_signed_in_browser_session_reaches_it(): void
    {
        $this->getJson('/api/user/data-export')->assertUnauthorized();
        $this->postJson('/api/user/deletion-request')->assertUnauthorized();

        $options = MachineTokenOptions::fromInput($this->user, $this->tenant, ['api:write'], [$this->workspace->id], []);
        $plain = app(MachineTokenIssuer::class)->issue($this->user, $this->tenant, 'agent', $options)['plain'];

        $this->assertContains($this->withToken($plain)->getJson('/api/user/data-export')->status(), [401, 403]);
        $this->assertContains($this->withToken($plain)->postJson('/api/user/deletion-request')->status(), [401, 403]);
        $this->assertNull($this->user->refresh()->deletion_requested_at);
    }

    public function test_a_deletion_request_is_recorded_shown_to_admins_and_can_be_withdrawn(): void
    {
        $this->actingAs($this->user)->postJson('/api/user/deletion-request')
            ->assertOk()
            ->assertJsonPath('data.deletion_requested_at', fn ($value): bool => is_string($value) && $value !== '');

        $requestedAt = $this->user->refresh()->deletion_requested_at;
        $this->assertNotNull($requestedAt);
        Mail::assertSent(DeletionRequestedEmail::class, fn (DeletionRequestedEmail $mail): bool => $mail->hasTo('admin@acme.test') && $mail->requester->is($this->user));
        $this->assertDatabaseHas('audit_log', ['event' => 'user.deletion_requested', 'actor_subject_id' => (string) $this->user->id]);

        // Asking again keeps the first date and sends nothing more.
        $this->travel(2)->days();
        $this->actingAs($this->user)->postJson('/api/user/deletion-request')->assertOk();
        $this->assertTrue($requestedAt->equalTo($this->user->refresh()->deletion_requested_at));
        Mail::assertSentCount(1);

        $this->actingAs($this->user)->getJson('/api/auth/me')->assertJsonPath('data.deletion_requested_at', $requestedAt->toIso8601String());
        $listed = collect($this->actingAs($this->admin)->getJson('/api/admin/users')->assertOk()->json('data'))->firstWhere('id', $this->user->id);
        $this->assertNotNull($listed['deletion_requested_at']);

        $this->actingAs($this->user)->deleteJson('/api/user/deletion-request')
            ->assertOk()
            ->assertJsonPath('data.deletion_requested_at', null);
        $this->assertNull($this->user->refresh()->deletion_requested_at);
        $this->assertDatabaseHas('audit_log', ['event' => 'user.deletion_request_cancelled']);
    }

    public function test_the_last_administrator_cannot_ask_for_deletion(): void
    {
        $this->actingAs($this->admin)->postJson('/api/user/deletion-request')->assertStatus(409);
        $this->assertNull($this->admin->refresh()->deletion_requested_at);
        $this->assertSame(0, AuditLog::query()->where('event', 'user.deletion_requested')->count());
    }
}
