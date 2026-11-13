<?php

namespace Tests\Feature\GitHubVault;

use App\Domain\Vault\Store\GitHub\GitHubAppAuth;
use App\Models\GitHubInstallation;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\TenantVaultStore;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\InteractsWithFakeGitHub;
use Tests\TestCase;

/** Connection and authentication: GitHub App and fine-grained PAT (#439). */
class GitHubConnectTest extends TestCase
{
    use InteractsWithFakeGitHub;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFakeGitHub(app: true);
        $this->github->createRepo('acme/notes')->createRepo('acme/other');
        $this->github->installApp(77, ['acme/notes', 'acme/other']);
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);
        $this->owner = User::factory()->create(['name' => 'Owner']);
        Membership::create(['subject_id' => (string) $this->owner->id, 'tenant_id' => $this->tenant->id, 'role' => 'owner']);
    }

    protected function tearDown(): void
    {
        $this->tearDownFakeGitHub();
        parent::tearDown();
    }

    public function test_the_app_jwt_is_rs256_short_lived_and_verified_by_github(): void
    {
        $jwt = app(GitHubAppAuth::class)->jwt(1_800_000_000);
        [$header, $claims] = array_map(fn ($part) => json_decode(base64_decode(strtr($part, '-_', '+/')), true), array_slice(explode('.', $jwt), 0, 2));

        $this->assertSame(['alg' => 'RS256', 'typ' => 'JWT'], $header);
        $this->assertSame('4242', $claims['iss']);
        $this->assertLessThanOrEqual(600, $claims['exp'] - $claims['iat']);

        $token = app(GitHubAppAuth::class)->installationToken(77, 'notes');
        $this->assertStringStartsWith('ghs_', $token['token']);
        $this->assertSame(['acme/notes'], $this->github->tokens[$token['token']]['repos'], 'Token restricted to one repository (V5).');
        $this->assertSame($token, app(GitHubAppAuth::class)->installationToken(77, 'notes'), 'Cached until 5 minutes before expiry.');
        $this->assertSame(1, $this->github->countRequests('POST', '/access_tokens'));
    }

    public function test_install_flow_binds_the_installation_to_the_tenant_with_a_single_use_state(): void
    {
        $url = $this->actingAs($this->owner)->postJson("/api/admin/tenants/{$this->tenant->id}/github/install")
            ->assertOk()->json('data.url');
        $this->assertStringStartsWith('https://github.test/apps/jotter-vault-test/installations/new?state=', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        // Only the hash of the state is stored.
        $this->assertDatabaseMissing('github_install_states', ['nonce_hash' => $query['state']]);

        $this->actingAs($this->owner)->get('/api/github/setup?installation_id=77&setup_action=install&state='.$query['state'])
            ->assertRedirect('/?storage=github&tenant='.$this->tenant->id.'&installation=77');

        $this->assertDatabaseHas('github_installations', ['tenant_id' => $this->tenant->id, 'installation_id' => 77, 'account_login' => 'acme']);
        $this->assertDatabaseHas('audit_log', ['event' => 'vault_store.github_installed']);

        // Reused state.
        $this->actingAs($this->owner)->get('/api/github/setup?installation_id=77&state='.$query['state'])->assertForbidden();
        // Unknown state.
        $this->actingAs($this->owner)->get('/api/github/setup?installation_id=77&state=forged')->assertForbidden();

        $repos = $this->actingAs($this->owner)->getJson("/api/admin/tenants/{$this->tenant->id}/github/installations/77/repositories")
            ->assertOk()->json('data.repositories');
        $this->assertSame(['acme/notes', 'acme/other'], array_column($repos, 'full_name'));
    }

    public function test_an_expired_state_or_another_admin_is_refused(): void
    {
        $url = $this->actingAs($this->owner)->postJson("/api/admin/tenants/{$this->tenant->id}/github/install")->json('data.url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $intruder = User::factory()->create(['is_admin' => true]);
        $this->actingAs($intruder)->get('/api/github/setup?installation_id=77&state='.$query['state'])->assertForbidden();

        $url = $this->actingAs($this->owner)->postJson("/api/admin/tenants/{$this->tenant->id}/github/install")->json('data.url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->travel(16)->minutes();
        $this->actingAs($this->owner)->get('/api/github/setup?installation_id=77&state='.$query['state'])->assertForbidden();
        $this->assertSame(0, GitHubInstallation::query()->count());
    }

    public function test_a_store_may_only_use_an_installation_its_tenant_connected(): void
    {
        $other = Tenant::create(['slug' => 'other', 'name' => 'Other']);
        GitHubInstallation::create(['tenant_id' => $other->id, 'installation_id' => 77]);

        $payload = [
            'name' => 'Notes', 'driver' => 'github',
            'settings' => ['owner' => 'acme', 'repo' => 'notes', 'branch' => 'main', 'installation_id' => 77, 'controller_confirmed' => true],
        ];
        $this->actingAs($this->owner)->postJson("/api/admin/tenants/{$this->tenant->id}/vault-stores", $payload)
            ->assertStatus(422)->assertJsonValidationErrors(['settings.installation_id']);

        GitHubInstallation::create(['tenant_id' => $this->tenant->id, 'installation_id' => 77]);
        $this->actingAs($this->owner)->postJson("/api/admin/tenants/{$this->tenant->id}/vault-stores", $payload)
            ->assertCreated()->assertJsonPath('data.settings.installation_id', 77)->assertJsonPath('data.has_credentials', false);

        // D1: one store per repository and branch.
        $this->actingAs($this->owner)->postJson("/api/admin/tenants/{$this->tenant->id}/vault-stores", ['name' => 'Again'] + $payload)
            ->assertStatus(422)->assertJsonValidationErrors(['settings.repo']);
    }

    public function test_connection_test_is_read_only_and_reports_ok(): void
    {
        GitHubInstallation::create(['tenant_id' => $this->tenant->id, 'installation_id' => 77]);
        $store = $this->appStore($this->tenant, 77);

        $this->actingAs($this->owner)->postJson("/api/admin/vault-stores/{$store->id}/test")
            ->assertOk()->assertJsonPath('data.status', 'ok');

        $this->assertSame(0, $this->github->writeCount(), 'The probe never commits.');
        $this->assertTrue($store->fresh()->settings['repository_empty']);
    }

    public function test_pat_mode_needs_a_token_and_confirmation_and_records_the_expiry(): void
    {
        $base = ['name' => 'PAT', 'driver' => 'github', 'settings' => ['owner' => 'acme', 'repo' => 'notes', 'branch' => 'main']];
        $this->actingAs($this->owner)->postJson("/api/admin/tenants/{$this->tenant->id}/vault-stores", $base + ['credentials' => ['token' => 'github_pat_x']])
            ->assertStatus(422)->assertJsonValidationErrors(['settings.controller_confirmed']);
        $base['settings']['controller_confirmed'] = true;
        $this->actingAs($this->owner)->postJson("/api/admin/tenants/{$this->tenant->id}/vault-stores", $base)
            ->assertStatus(422)->assertJsonValidationErrors(['credentials.token']);

        $id = $this->actingAs($this->owner)->postJson("/api/admin/tenants/{$this->tenant->id}/vault-stores", $base + ['credentials' => ['token' => 'github_pat_x']])
            ->assertCreated()->assertJsonMissingPath('data.credentials')->json('data.id');
        $this->assertNotEmpty(TenantVaultStore::find($id)->credentials['webhook_secret']);

        $this->github->allowPat('github_pat_x', 'acme/notes');
        $this->github->extraHeaders = ['github-authentication-token-expiration' => '2026-11-20 10:00:00 UTC'];
        $this->actingAs($this->owner)->postJson("/api/admin/vault-stores/{$id}/test")->assertOk();
        $this->assertSame('2026-11-20T10:00:00+00:00', TenantVaultStore::find($id)->settings['token_expires_at']);

        $hook = $this->actingAs($this->owner)->getJson("/api/admin/vault-stores/{$id}/github/webhook")->assertOk();
        $hook->assertJsonPath('data.mode', 'pat')->assertJsonPath('data.url', url('/webhooks/github'));
        $this->assertSame(TenantVaultStore::find($id)->credentials['webhook_secret'], $hook->json('data.secret'));
    }

    public function test_a_public_repository_or_a_read_only_token_fails_the_test(): void
    {
        $this->github->repos['acme/notes']['private'] = false;
        $store = $this->patStore($this->tenant);
        $this->actingAs($this->owner)->postJson("/api/admin/vault-stores/{$store->id}/test")
            ->assertStatus(422)->assertJsonPath('data.status', 'failed');
        $this->assertStringContainsString('public', (string) $store->fresh()->last_error);

        $this->github->repos['acme/notes']['private'] = true;
        $this->github->repos['acme/notes']['push'] = false;
        $this->actingAs($this->owner)->postJson("/api/admin/vault-stores/{$store->id}/test")->assertStatus(422);
        $this->assertStringContainsString('push', (string) $store->fresh()->last_error);
    }

    public function test_without_github_in_the_drivers_nothing_is_exposed(): void
    {
        config(['jotter.vault_stores.drivers' => ['local']]);

        $this->actingAs($this->owner)->postJson("/api/admin/tenants/{$this->tenant->id}/github/install")->assertNotFound();
        $this->actingAs($this->owner)->getJson("/api/admin/tenants/{$this->tenant->id}/github")->assertNotFound();
        $this->postJson('/webhooks/github', [])->assertNotFound();
    }

    public function test_the_beta_list_limits_which_tenants_may_connect(): void
    {
        config(['jotter.github.beta_tenants' => ['someone-else']]);
        $this->actingAs($this->owner)->postJson("/api/admin/tenants/{$this->tenant->id}/github/install")->assertForbidden();
        $this->actingAs($this->owner)->postJson("/api/admin/tenants/{$this->tenant->id}/vault-stores", [
            'name' => 'PAT', 'driver' => 'github',
            'settings' => ['owner' => 'acme', 'repo' => 'notes', 'controller_confirmed' => true],
            'credentials' => ['token' => 'x'],
        ])->assertStatus(422)->assertJsonValidationErrors(['driver']);

        config(['jotter.github.beta_tenants' => ['acme']]);
        $this->actingAs($this->owner)->postJson("/api/admin/tenants/{$this->tenant->id}/github/install")->assertOk();
    }

    public function test_the_manifest_flow_writes_the_key_outside_public_and_shows_the_env_once(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($this->owner)->getJson('/api/admin/github/manifest')->assertForbidden();

        $manifest = $this->actingAs($admin)->getJson('/api/admin/github/manifest?organization=acme')->assertOk();
        $this->assertSame(['contents' => 'write', 'metadata' => 'read'], $manifest->json('data.manifest.default_permissions'));
        $this->assertSame(['push'], $manifest->json('data.manifest.default_events'));
        parse_str((string) parse_url($manifest->json('data.post_url'), PHP_URL_QUERY), $query);

        $this->actingAs($admin)->getJson('/api/admin/github/manifest/callback?code=abc&state=wrong')->assertForbidden();
        $manifest = $this->actingAs($admin)->getJson('/api/admin/github/manifest')->assertOk();
        parse_str((string) parse_url($manifest->json('data.post_url'), PHP_URL_QUERY), $query);
        $result = $this->actingAs($admin)->getJson('/api/admin/github/manifest/callback?code=abc&state='.$query['state'])->assertOk();

        $path = $result->json('data.env.JOTTER_GITHUB_APP_PRIVATE_KEY_PATH');
        $this->assertStringStartsWith(storage_path('app/private/'), $path);
        $this->assertSame('600', substr(sprintf('%o', fileperms($path)), -3));
        $this->assertSame('999', $result->json('data.env.JOTTER_GITHUB_APP_ID'));
        unlink($path);
    }

    public function test_doctor_reports_app_problems_and_an_expiring_token(): void
    {
        $store = $this->patStore($this->tenant);
        $store->forceFill(['settings' => $store->settings + ['token_expires_at' => now()->addDays(5)->toIso8601String()]])->save();
        config(['jotter.github.private_key_path' => '/nonexistent.pem']);

        $check = (new \App\Domain\Vault\Store\GitHub\GitHubDoctor)->check(true)->toArray();

        $this->assertSame('warn', $check['status']);
        $this->assertStringContainsString('private key is not readable', $check['message']);
        $this->assertStringContainsString('expires in', $check['message']);
    }
}
