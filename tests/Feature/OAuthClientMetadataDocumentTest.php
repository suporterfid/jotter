<?php

namespace Tests\Feature;

use App\Domain\OAuth\HostResolver;
use App\Models\Membership;
use App\Models\OAuthClient;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\Support\OAuthFlow;
use Tests\TestCase;

/**
 * Issue #411: a client_id that is an https URL is resolved from its Client ID
 * Metadata Document instead of dynamic registration.
 */
final class OAuthClientMetadataDocumentTest extends TestCase
{
    use OAuthFlow;
    use RefreshDatabase;

    private const CLIENT_ID = 'https://client.example.com/oauth/metadata.json';

    private Workspace $brain;
    private User $user;

    /** @var array<string, list<string>> */
    private array $dns = ['client.example.com' => ['93.184.216.34']];

    protected function setUp(): void
    {
        parent::setUp();
        config(['jotter.auth_bypass' => false, 'jotter.auth_provider' => 'local', 'jotter.oauth.enabled' => true, 'app.url' => 'https://brain.example.test']);

        $this->app->instance(HostResolver::class, new class($this->dns) implements HostResolver
        {
            /** @param array<string, list<string>> $dns */
            public function __construct(public array $dns) {}

            public function resolve(string $host): array
            {
                return $this->dns[$host] ?? [];
            }
        });

        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);
        $this->brain = Workspace::create(['tenant_id' => $tenant->id, 'slug' => 'brain', 'name' => 'Brain', 'vault_path' => storage_path('app/vaults/cimd_'.uniqid())]);
        $this->user = User::factory()->create(['is_admin' => false, 'email' => 'joe@acme.test']);
        Membership::create(['tenant_id' => $tenant->id, 'workspace_id' => $this->brain->id, 'subject_id' => (string) $this->user->id, 'role' => 'editor']);
    }

    public function test_metadata_announces_client_id_metadata_documents(): void
    {
        $this->getJson('/.well-known/oauth-authorization-server')->assertOk()->assertJsonPath('client_id_metadata_document_supported', true);

        config(['jotter.oauth.cimd.enabled' => false]);
        $this->getJson('/.well-known/oauth-authorization-server')->assertOk()->assertJsonPath('client_id_metadata_document_supported', false);
    }

    public function test_full_flow_with_a_metadata_document_client_and_the_document_is_cached(): void
    {
        Http::fake([self::CLIENT_ID => Http::response($this->document(), 200, ['Cache-Control' => 'max-age=600'])]);

        $consent = $this->actingAs($this->user)->get($this->authorizeUrl(self::CLIENT_ID))->assertOk();
        $consent->assertSee('Example Agent')->assertSee('client.example.com')->assertSee('data-testid="oauth-client-host"', false);

        $code = $this->approve($this->consentRequestId($consent), [$this->brain->id])['code'];
        $token = $this->exchange(self::CLIENT_ID, $code)->assertOk()->json('access_token');
        $this->assertStringStartsWith('jt_oat_', $token);

        $client = OAuthClient::query()->where('client_id', self::CLIENT_ID)->sole();
        $this->assertSame(OAuthClient::KIND_CIMD, $client->kind);
        $this->assertSame('none', $client->auth_method);
        $this->assertEqualsWithDelta(now()->addSeconds(600)->getTimestamp(), $client->metadata_expires_at->getTimestamp(), 5);

        // A second connection within the TTL reuses the cached document.
        $this->actingAs($this->user)->get($this->authorizeUrl(self::CLIENT_ID))->assertOk();
        Http::assertSentCount(1);

        // Once expired it is fetched again.
        $client->forceFill(['metadata_expires_at' => now()->subMinute()])->save();
        $this->actingAs($this->user)->get($this->authorizeUrl(self::CLIENT_ID))->assertOk();
        Http::assertSentCount(2);
        Http::assertSent(static fn (HttpRequest $request): bool => $request->url() === self::CLIENT_ID);

        $this->flushHeaders();
        $this->withToken($token)->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertOk();
    }

    public function test_a_document_naming_another_client_id_is_refused(): void
    {
        Http::fake([self::CLIENT_ID => Http::response($this->document(['client_id' => 'https://evil.example.com/meta.json']))]);

        $this->refused('names a different client_id');
    }

    public function test_a_redirect_uri_outside_the_allowlist_is_refused(): void
    {
        Http::fake([self::CLIENT_ID => Http::response($this->document(['redirect_uris' => ['https://evil.example.com/cb']]))]);

        $this->refused('Redirect URI not allowed');
    }

    public function test_confidential_clients_are_refused(): void
    {
        Http::fake([self::CLIENT_ID => Http::response($this->document(['token_endpoint_auth_method' => 'client_secret_basic']))]);

        $this->refused('Only public clients');
    }

    public function test_a_host_resolving_to_a_private_address_is_never_fetched(): void
    {
        Http::fake();
        $this->app->instance(HostResolver::class, new class implements HostResolver
        {
            public function resolve(string $host): array
            {
                return ['10.0.0.5'];
            }
        });

        $this->refused('private or reserved address');
        Http::assertNothingSent();
    }

    public function test_ip_literal_hosts_and_non_https_urls_are_not_metadata_documents(): void
    {
        Http::fake();

        $this->actingAs($this->user)->get($this->authorizeUrl('https://169.254.169.254/latest/meta-data'))
            ->assertStatus(400)->assertSee('not an IP address');
        // Plain http or a bare origin is just an unknown client_id.
        $this->actingAs($this->user)->get($this->authorizeUrl('http://client.example.com/meta.json'))
            ->assertStatus(400)->assertSee(__('oauth.error_unknown_client'));
        $this->actingAs($this->user)->get($this->authorizeUrl('https://client.example.com/'))
            ->assertStatus(400)->assertSee(__('oauth.error_unknown_client'));
        Http::assertNothingSent();
    }

    public function test_oversized_documents_are_refused(): void
    {
        Http::fake([self::CLIENT_ID => Http::response($this->document(['client_name' => str_repeat('x', 6000)]))]);

        $this->refused('too large');
    }

    public function test_error_answers_are_refused(): void
    {
        Http::fake([self::CLIENT_ID => Http::response('nope', 404)]);

        $this->refused('HTTP 404');
    }

    public function test_metadata_documents_can_be_turned_off(): void
    {
        config(['jotter.oauth.cimd.enabled' => false]);
        Http::fake();

        $this->actingAs($this->user)->get($this->authorizeUrl(self::CLIENT_ID))
            ->assertStatus(400)->assertSee(__('oauth.error_unknown_client'));
        Http::assertNothingSent();
    }

    private function refused(string $reason): void
    {
        $this->actingAs($this->user)->get($this->authorizeUrl(self::CLIENT_ID))
            ->assertStatus(400)
            ->assertSee($reason);
        $this->assertSame(0, OAuthClient::query()->where('client_id', self::CLIENT_ID)->count(), 'nothing is cached');
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function document(array $overrides = []): array
    {
        return array_merge([
            'client_id' => self::CLIENT_ID,
            'client_name' => 'Example Agent',
            'redirect_uris' => [$this->redirectUri],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
        ], $overrides);
    }
}
