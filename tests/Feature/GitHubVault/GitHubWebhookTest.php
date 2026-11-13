<?php

namespace Tests\Feature\GitHubVault;

use App\Domain\Vault\Store\GitHub\GitHubSync;
use App\Models\GitHubInstallation;
use App\Models\Note;
use App\Models\Tenant;
use App\Models\TenantVaultStore;
use App\Models\VaultSyncState;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithFakeGitHub;
use Tests\TestCase;

/** `POST /webhooks/github`: external pushes in seconds (#440, G5). */
class GitHubWebhookTest extends TestCase
{
    use InteractsWithFakeGitHub;
    use RefreshDatabase;

    private Tenant $tenant;

    private TenantVaultStore $store;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFakeGitHub(app: true);
        $this->github->createRepo('acme/notes', ['Home.md' => "# Home\n"]);
        $this->github->installApp(77, ['acme/notes']);
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);
        GitHubInstallation::create(['tenant_id' => $this->tenant->id, 'installation_id' => 77]);
        $this->store = $this->appStore($this->tenant, 77);
        $this->workspace = $this->workspaceOn($this->store);
        app(GitHubSync::class)->pull($this->store);
    }

    protected function tearDown(): void
    {
        $this->tearDownFakeGitHub();
        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function deliver(string $event, array $payload, ?string $secret = 'webhook-secret', ?string $delivery = null)
    {
        $body = json_encode($payload);
        $headers = [
            'X-GitHub-Event' => $event,
            'X-GitHub-Delivery' => $delivery ?? (string) \Illuminate\Support\Str::uuid(),
            'Content-Type' => 'application/json',
        ];
        if ($secret !== null) {
            $headers['X-Hub-Signature-256'] = 'sha256='.hash_hmac('sha256', $body, $secret);
        }

        return $this->call('POST', '/webhooks/github', [], [], [], $this->transformHeadersToServerVars($headers), $body);
    }

    private function pushPayload(string $branch = 'main', int $installation = 77, string $repo = 'acme/notes'): array
    {
        return [
            'ref' => 'refs/heads/'.$branch,
            'repository' => ['full_name' => $repo],
            'installation' => ['id' => $installation],
            'sender' => ['login' => 'octocat'],
            'commits' => [['added' => ['New.md'], 'modified' => [], 'removed' => []]],
        ];
    }

    public function test_a_signed_push_answers_202_and_the_note_appears_right_after(): void
    {
        $this->github->push('acme/notes', ['New.md' => "# New\n\nFrom a laptop.\n"]);

        $this->deliver('push', $this->pushPayload())->assertStatus(202)->assertJsonPath('stores', 1);

        $note = Note::query()->where('path', 'New.md')->first();
        $this->assertNotNull($note, 'Pulled after the response, in the same request lifecycle.');
        $state = VaultSyncState::query()->where('vault_store_id', $this->store->id)->first();
        $this->assertFalse($state->pull_pending);
        $this->assertNotNull($state->last_webhook_at);
    }

    public function test_an_invalid_signature_is_401_and_triggers_nothing(): void
    {
        $this->github->push('acme/notes', ['New.md' => "# New\n"]);
        $before = count($this->github->requests);

        $this->deliver('push', $this->pushPayload(), secret: 'wrong')->assertStatus(401);
        $this->deliver('push', $this->pushPayload(), secret: null)->assertStatus(401);

        $this->assertSame($before, count($this->github->requests));
        $this->assertNull(Note::query()->where('path', 'New.md')->first());
        $this->assertDatabaseCount('github_webhook_deliveries', 0);
    }

    public function test_a_repeated_delivery_does_not_pull_twice(): void
    {
        $this->github->push('acme/notes', ['New.md' => "# New\n"]);
        $this->deliver('push', $this->pushPayload(), delivery: 'd-1')->assertStatus(202);
        $refGets = $this->github->countRequests('GET', '/git/ref/');

        $this->deliver('push', $this->pushPayload(), delivery: 'd-1')->assertOk()->assertJsonPath('message', 'Duplicate delivery ignored');

        $this->assertSame($refGets, $this->github->countRequests('GET', '/git/ref/'));
    }

    public function test_pushes_to_other_branches_or_installations_are_ignored(): void
    {
        $this->deliver('push', $this->pushPayload(branch: 'feature'))->assertStatus(202)->assertJsonPath('message', 'No store follows this branch');
        $this->deliver('push', $this->pushPayload(installation: 999))->assertStatus(202)->assertJsonPath('message', 'No store follows this branch');
        $this->assertNull(VaultSyncState::query()->where('vault_store_id', $this->store->id)->value('last_webhook_at'));
    }

    public function test_a_pull_left_unfinished_is_completed_by_the_cron(): void
    {
        config(['jotter.github.webhook_pull_files' => 1]);
        $this->github->push('acme/notes', ['A.md' => "# A\n", 'B.md' => "# B\n", 'C.md' => "# C\n"]);

        $this->deliver('push', $this->pushPayload())->assertStatus(202);
        $this->assertTrue(VaultSyncState::query()->where('vault_store_id', $this->store->id)->value('pull_pending'));

        $this->artisan('vault:pull', ['--pending' => true])->assertSuccessful();
        $this->assertSame(['A.md', 'B.md', 'C.md', 'Home.md'], Note::query()->orderBy('path')->pluck('path')->all());
    }

    public function test_uninstalling_the_app_fails_the_store_at_once(): void
    {
        $this->deliver('installation', ['action' => 'deleted', 'installation' => ['id' => 77]])->assertStatus(202);

        $this->assertSame('failed', $this->store->fresh()->status);
        $this->assertNotNull(GitHubInstallation::query()->first()->suspended_at);
        $this->assertSame('degraded', $this->workspace->fresh()->storage_state);
    }

    public function test_removing_the_repository_from_the_installation_fails_its_store(): void
    {
        $this->deliver('installation_repositories', [
            'action' => 'removed',
            'installation' => ['id' => 77],
            'repositories_removed' => [['full_name' => 'acme/notes']],
        ])->assertStatus(202);

        $this->assertSame('failed', $this->store->fresh()->status);
    }

    public function test_pat_stores_verify_with_their_own_repository_secret(): void
    {
        $this->github->createRepo('solo/vault', ['x.md' => "# X\n"]);
        $pat = $this->patStore($this->tenant, 'solo/vault', token: 'pat-solo');
        $this->workspaceOn($pat, 'solo');
        app(GitHubSync::class)->pull($pat);
        $this->github->push('solo/vault', ['y.md' => "# Y\n"]);
        $payload = ['ref' => 'refs/heads/main', 'repository' => ['full_name' => 'solo/vault'], 'sender' => ['login' => 'me']];

        // The App secret cannot trigger a PAT store, nor can another store's secret.
        $this->deliver('push', $payload, secret: 'webhook-secret')->assertStatus(202)->assertJsonPath('message', 'No store follows this branch');
        $this->deliver('push', $payload, secret: 'repo-secret')->assertStatus(202)->assertJsonPath('stores', 1);

        $this->assertNotNull(Note::query()->where('path', 'y.md')->first());
    }

    public function test_bodies_over_one_megabyte_are_refused(): void
    {
        $this->deliver('push', ['ref' => 'refs/heads/main', 'pad' => str_repeat('x', 1024 * 1024)])->assertStatus(413);
    }
}
