<?php

namespace Tests\Feature;

use App\Domain\OAuth\OAuthLoginReturn;
use App\Models\Membership;
use App\Models\OAuthGrant;
use App\Models\Tenant;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\OAuthFlow;
use Tests\TestCase;

/**
 * With JOTTER_AUTH_PROVIDER=grandpasson the broker always sends people back to
 * APP_URL after sign-in, so the `?return_to=` the consent screen added is lost.
 * The SPA shell route resumes the consent from the session instead.
 *
 * AUTHSESSID goes through $_COOKIE, as in GrandpaSSOnAdminEscalationTest: the
 * real cookie is set in plain text by GrandpaSSOn, never by Laravel.
 */
final class OAuthGrandpaSSOnLoginReturnTest extends TestCase
{
    use OAuthFlow;
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
            'jotter.oauth.enabled' => true,
            'app.url' => 'https://brain.example.test',
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
        $this->brain = Workspace::create(['tenant_id' => $tenant->id, 'slug' => 'brain', 'name' => 'Brain', 'vault_path' => storage_path('app/vaults/oauth_sso_'.uniqid())]);

        $this->ssoUserId = (string) Str::uuid();
        DB::table(self::PREFIX.'users')->insert(['id' => $this->ssoUserId, 'primary_email' => 'joe@acme.test', 'display_name' => 'Joe', 'status' => 'active']);
        Membership::create(['tenant_id' => $tenant->id, 'workspace_id' => $this->brain->id, 'subject_id' => $this->ssoUserId, 'role' => 'editor']);
    }

    protected function tearDown(): void
    {
        unset($_COOKIE['AUTHSESSID']);
        Schema::dropIfExists(self::PREFIX.'sessions');
        Schema::dropIfExists(self::PREFIX.'users');

        parent::tearDown();
    }

    public function test_after_grandpasson_sign_in_the_shell_route_resumes_the_consent(): void
    {
        $client = $this->registerClient();
        $url = $this->authorizeUrl($client['client_id']);

        // Not signed in yet: sent to the SPA login, consent remembered in the session.
        $this->get($url)->assertRedirect();
        $this->assertTrue(session()->has(OAuthLoginReturn::SESSION_KEY));

        // Signed in at GrandpaSSOn, which returns to APP_URL with its own query.
        $this->signInAtGrandpaSSOn();
        $location = (string) $this->get('/?code=broker-code&state=broker-state')->assertRedirect()->headers->get('Location');
        $resumed = (string) parse_url($location, PHP_URL_PATH).'?'.(string) parse_url($location, PHP_URL_QUERY);
        $this->assertStringStartsWith('/oauth/authorize?', $resumed);
        $this->assertStringContainsString('client_id='.$client['client_id'], $resumed);
        $this->assertFalse(session()->has(OAuthLoginReturn::SESSION_KEY), 'the return is used once');

        $consent = $this->get($resumed)->assertOk()->assertSee('Brain');
        $query = $this->approve($this->consentRequestId($consent), [$this->brain->id]);
        $this->assertSame('state-123', $query['state']);

        $token = $this->exchange($client['client_id'], $query['code'])->assertOk()->json('access_token');
        unset($_COOKIE['AUTHSESSID']);

        $this->flushHeaders();
        $workspaces = $this->withToken($token)->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'list_workspaces', 'arguments' => []]])
            ->assertOk()
            ->json('result.content.0.text');
        $this->assertSame(['brain'], array_column(json_decode((string) $workspaces, true), 'slug'), 'the SSO user reaches the consented workspace through the OAuth token');

        // The administrator list names the local account behind the SSO subject.
        $this->assertSame($this->ssoUserId, OAuthGrant::query()->sole()->subject_id);
        $this->assertNotNull(OAuthGrant::query()->sole()->user_id);
    }

    public function test_the_shell_is_served_when_nothing_is_pending(): void
    {
        $this->signInAtGrandpaSSOn();

        $this->get('/')->assertOk()->assertViewIs('app');
    }

    public function test_a_pending_consent_waits_until_the_person_is_signed_in(): void
    {
        $this->withSession([OAuthLoginReturn::SESSION_KEY => ['path' => '/oauth/authorize?client_id=jtc_x', 'expires' => time() + 600]])
            ->get('/')
            ->assertOk()
            ->assertViewIs('app');

        $this->assertTrue(session()->has(OAuthLoginReturn::SESSION_KEY));
    }

    public function test_an_expired_or_foreign_return_is_dropped_not_followed(): void
    {
        $this->signInAtGrandpaSSOn();

        $this->withSession([OAuthLoginReturn::SESSION_KEY => ['path' => '/oauth/authorize?client_id=jtc_x', 'expires' => time() - 1]])
            ->get('/')
            ->assertOk()
            ->assertViewIs('app');
        $this->assertFalse(session()->has(OAuthLoginReturn::SESSION_KEY));

        $this->withSession([OAuthLoginReturn::SESSION_KEY => ['path' => 'https://evil.example/x', 'expires' => time() + 600]])
            ->get('/')
            ->assertOk()
            ->assertViewIs('app');
    }

    private function signInAtGrandpaSSOn(): void
    {
        $sessionId = 'sso-session-'.uniqid();
        DB::table(self::PREFIX.'sessions')->insert(['id' => $sessionId, 'user_id' => $this->ssoUserId, 'expires_at' => time() + 3600]);
        $_COOKIE['AUTHSESSID'] = $sessionId;
    }
}
