<?php

namespace Tests\Feature\GitHubVault;

use App\Domain\Vault\Store\GitHub\GitHubAppAuth;
use App\Domain\Vault\Store\GitHub\GitHubAppRegistry;
use App\Models\GitHubAppRegistration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\InteractsWithFakeGitHub;
use Tests\TestCase;

/** Registering the GitHub App from Administration → Storage (manifest flow, no .env edits). */
class GitHubAppRegistrationTest extends TestCase
{
    use InteractsWithFakeGitHub;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFakeGitHub(app: false);
        config(['jotter.github.webhook_secret' => null]);
        $this->github->createRepo('acme/notes');
        $this->admin = User::factory()->create(['is_admin' => true]);
    }

    protected function tearDown(): void
    {
        $this->tearDownFakeGitHub();
        parent::tearDown();
    }

    public function test_only_instance_admins_see_or_start_the_registration(): void
    {
        $member = User::factory()->create();
        $this->actingAs($member)->getJson('/api/admin/github/app')->assertForbidden();
        $this->actingAs($member)->getJson('/api/admin/github/manifest')->assertForbidden();
        $this->actingAs($member)->deleteJson('/api/admin/github/app')->assertForbidden();

        $status = $this->actingAs($this->admin)->getJson('/api/admin/github/app')->assertOk();
        $this->assertNull($status->json('data.source'));
        $this->assertNull($status->json('data.app_id'));
    }

    public function test_the_manifest_flow_stores_the_app_encrypted_and_never_shows_the_key(): void
    {
        $manifest = $this->actingAs($this->admin)->getJson('/api/admin/github/manifest?organization=acme')->assertOk();
        $this->assertSame('https://github.test/organizations/acme/settings/apps/new', strtok((string) $manifest->json('data.post_url'), '?'));
        $this->assertSame(['contents' => 'write', 'metadata' => 'read'], $manifest->json('data.manifest.default_permissions'));
        $this->assertSame(['push'], $manifest->json('data.manifest.default_events'));
        parse_str((string) parse_url($manifest->json('data.post_url'), PHP_URL_QUERY), $query);

        $this->actingAs($this->admin)->get('/api/admin/github/manifest/callback?code=abc&state='.$query['state'])
            ->assertRedirect('/?storage=github-app&result=registered');

        $raw = DB::table('github_app_registrations')->first();
        $this->assertSame('999', $raw->app_id);
        $this->assertSame('acme', $raw->owner);
        $this->assertStringNotContainsString('PRIVATE KEY', $raw->private_key, 'Encrypted at rest.');
        $this->assertNotSame('whsec', $raw->webhook_secret);
        $this->assertSame([], glob(storage_path('app/private/github-app-*.pem')) ?: [], 'No key file is written.');

        $status = $this->actingAs($this->admin)->getJson('/api/admin/github/app')->assertOk();
        $this->assertSame('database', $status->json('data.source'));
        $this->assertSame('jotter-vault-test', $status->json('data.slug'));
        $this->assertTrue($status->json('data.webhook_secret_set'));
        $this->assertSame([], $status->json('data.problems'));
        $this->assertStringNotContainsString('PRIVATE KEY', $status->getContent());
        $this->assertStringNotContainsString('whsec', $status->getContent());

        // The stored App signs JWTs GitHub accepts and verifies webhooks.
        $this->github->installApp(77, ['acme/notes']);
        $auth = new GitHubAppAuth;
        $this->assertSame('999', $auth->appId());
        $this->assertStringStartsWith('ghs_', $auth->installationToken(77, 'notes')['token']);
        $this->assertSame('whsec', $auth->webhookSecret());

        $body = (string) json_encode(['zen' => 'hi', 'hook_id' => 1]);
        $headers = [
            'Content-Type' => 'application/json',
            'X-GitHub-Event' => 'ping',
            'X-GitHub-Delivery' => 'd-1',
            'X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, 'whsec'),
        ];
        $this->call('POST', '/webhooks/github', [], [], [], $this->transformHeadersToServerVars($headers), $body)->assertOk();
    }

    public function test_the_callback_refuses_a_wrong_state_a_bad_code_and_a_second_app(): void
    {
        $this->actingAs($this->admin)->get('/api/admin/github/manifest/callback?code=abc&state=wrong')
            ->assertRedirect('/?storage=github-app&result=state_invalid');

        $manifest = $this->actingAs($this->admin)->getJson('/api/admin/github/manifest')->assertOk();
        parse_str((string) parse_url($manifest->json('data.post_url'), PHP_URL_QUERY), $query);
        $this->actingAs($this->admin)->get('/api/admin/github/manifest/callback?code=bad&state='.$query['state'])
            ->assertRedirect('/?storage=github-app&result=exchange_failed&detail=404');
        $this->assertSame(0, GitHubAppRegistration::query()->count());

        $this->actingAs($this->admin)->getJson('/api/admin/github/manifest?organization=not_valid!')->assertStatus(422);

        $manifest = $this->actingAs($this->admin)->getJson('/api/admin/github/manifest')->assertOk();
        parse_str((string) parse_url($manifest->json('data.post_url'), PHP_URL_QUERY), $query);
        $this->actingAs($this->admin)->get('/api/admin/github/manifest/callback?code=abc&state='.$query['state'])
            ->assertRedirect('/?storage=github-app&result=registered');
        $this->actingAs($this->admin)->getJson('/api/admin/github/manifest')->assertStatus(409);
    }

    public function test_forgetting_the_registered_app_allows_registering_again(): void
    {
        $manifest = $this->actingAs($this->admin)->getJson('/api/admin/github/manifest')->assertOk();
        parse_str((string) parse_url($manifest->json('data.post_url'), PHP_URL_QUERY), $query);
        $this->actingAs($this->admin)->get('/api/admin/github/manifest/callback?code=abc&state='.$query['state']);
        app(GitHubAppRegistry::class)->forget();

        $this->actingAs($this->admin)->deleteJson('/api/admin/github/app')->assertOk()->assertJsonPath('data.source', null);
        $this->assertSame(0, GitHubAppRegistration::query()->count());
        $this->assertDatabaseHas('audit_log', ['event' => 'vault_store.github_installed']);
        $this->actingAs($this->admin)->getJson('/api/admin/github/manifest')->assertOk();
    }
}
