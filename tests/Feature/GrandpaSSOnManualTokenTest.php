<?php

namespace Tests\Feature;

use App\Models\Identity;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Issue #414: with GrandpaSSOn, memberships are often stored under the SSO
 * user's UUID. Manual MCP tokens resolve the local user id, so the UUID must be
 * linked in `identities` (written on sign-in) for those memberships to apply.
 *
 * AUTHSESSID goes through $_COOKIE, as in GrandpaSSOnAdminEscalationTest.
 */
final class GrandpaSSOnManualTokenTest extends TestCase
{
    use RefreshDatabase;

    private const PREFIX = 'test_sso_';

    private Workspace $brain;
    private string $ssoUserId;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'jotter.auth_bypass' => false,
            'jotter.auth_provider' => 'grandpasson',
            'jotter.sso.db_prefix' => self::PREFIX,
        ]);

        Schema::create(self::PREFIX.'users', function ($table) {
            $table->string('id', 36)->primary();
            $table->string('primary_email');
            $table->string('display_name')->nullable();
            $table->string('status')->default('active');
        });
        Schema::create(self::PREFIX.'sessions', function ($table) {
            $table->string('id', 64)->primary();
            $table->string('user_id', 36)->nullable();
            $table->unsignedBigInteger('expires_at');
        });

        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);
        Tenant::create(['slug' => 'other', 'name' => 'Other']);
        $vault = storage_path('app/vaults/sso_token_'.uniqid());
        File::ensureDirectoryExists($vault);
        $this->brain = Workspace::create(['tenant_id' => $tenant->id, 'slug' => 'brain', 'name' => 'Brain', 'vault_path' => $vault]);

        $this->ssoUserId = (string) Str::uuid();
        DB::table(self::PREFIX.'users')->insert(['id' => $this->ssoUserId, 'primary_email' => 'joe@acme.test', 'display_name' => 'Joe', 'status' => 'active']);
        Membership::create(['tenant_id' => $tenant->id, 'workspace_id' => $this->brain->id, 'subject_id' => $this->ssoUserId, 'role' => 'editor']);
    }

    protected function tearDown(): void
    {
        unset($_COOKIE['AUTHSESSID']);
        File::deleteDirectory((string) $this->brain->vault_path);
        Schema::dropIfExists(self::PREFIX.'sessions');
        Schema::dropIfExists(self::PREFIX.'users');

        parent::tearDown();
    }

    public function test_sign_in_links_the_sso_uuid_to_the_local_account_once(): void
    {
        $this->signInAtGrandpaSSOn();
        $this->getJson('/api/auth/me')->assertOk();
        $this->getJson('/api/auth/me')->assertOk();

        $user = User::query()->where('email', 'joe@acme.test')->sole();
        $identity = Identity::query()->where('provider', 'grandpasson')->sole();
        $this->assertSame($this->ssoUserId, $identity->subject_id);
        $this->assertSame($user->id, $identity->user_id);
    }

    public function test_a_manual_token_reaches_workspaces_of_memberships_stored_under_the_uuid(): void
    {
        $this->signInAtGrandpaSSOn();
        $this->getJson('/api/auth/me')->assertOk();
        unset($_COOKIE['AUTHSESSID']);

        // No --tenant: inferred from the membership stored under the SSO UUID.
        $this->assertSame(0, Artisan::call('mcp:token', ['email' => 'joe@acme.test', '--name' => 'Hermes', '--json' => true]));
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('acme', $report['tenant']);

        $this->flushHeaders();
        $list = $this->withToken($report['token'])->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'list_workspaces', 'arguments' => []]])
            ->assertOk()
            ->json('result.content.0.text');
        $this->assertSame(['brain'], array_column(json_decode((string) $list, true), 'slug'));

        // Workspace-level authorization (AuthorizeWorkspaceAccess / NoteAccess) accepts it too.
        $notes = $this->withToken($report['token'])->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'list_notes', 'arguments' => ['workspace_id' => $this->brain->id]]])
            ->assertOk();
        $this->assertNotTrue($notes->json('result.isError'), (string) $notes->json('result.content.0.text'));
    }

    public function test_a_membership_stored_under_the_email_keeps_working(): void
    {
        Membership::query()->update(['subject_id' => 'joe@acme.test']);
        $user = User::factory()->create(['email' => 'joe@acme.test', 'is_admin' => false]);

        $this->assertSame(0, Artisan::call('mcp:token', ['email' => 'joe@acme.test', '--name' => 'Cursor', '--json' => true]));
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('acme', $report['tenant']);

        $list = $this->withToken($report['token'])->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'list_workspaces', 'arguments' => []]])
            ->assertOk()
            ->json('result.content.0.text');
        $this->assertSame(['brain'], array_column(json_decode((string) $list, true), 'slug'));
        $this->assertSame(0, Identity::query()->where('user_id', $user->id)->count());
    }

    private function signInAtGrandpaSSOn(): void
    {
        $sessionId = 'sso-session-'.uniqid();
        DB::table(self::PREFIX.'sessions')->insert(['id' => $sessionId, 'user_id' => $this->ssoUserId, 'expires_at' => time() + 3600]);
        $_COOKIE['AUTHSESSID'] = $sessionId;
    }
}
