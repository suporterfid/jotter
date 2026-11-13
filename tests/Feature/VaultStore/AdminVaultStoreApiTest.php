<?php

namespace Tests\Feature\VaultStore;

use App\Domain\Vault\Store\EndpointGuard;
use App\Models\AuditLog;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\TenantVaultStore;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AdminVaultStoreApiTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'wJalrXUtnFEMI-super-secret-key';

    private Tenant $tenant;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);
        $this->admin = User::factory()->create(['is_admin' => true]);
        // Tests never touch real DNS.
        $this->app->instance(EndpointGuard::class, new EndpointGuard(
            static fn (string $host): array => match ($host) {
                's3.example.com' => ['93.184.216.34'],
                'evil.example.com' => ['10.0.0.5'],
                default => [],
            },
            [],
        ));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/vaults/store-tests'));

        parent::tearDown();
    }

    public function test_without_a_remote_driver_nothing_is_exposed(): void
    {
        config(['jotter.vault_stores.drivers' => ['local']]);

        $this->actingAs($this->admin)->getJson('/api/admin/vault-stores/config')
            ->assertOk()->assertJsonPath('data.enabled', false)->assertJsonPath('data.drivers', []);
        $this->actingAs($this->admin)->getJson("/api/admin/tenants/{$this->tenant->id}/vault-stores")->assertNotFound();
        $this->actingAs($this->admin)->postJson("/api/admin/tenants/{$this->tenant->id}/vault-stores", [
            'name' => 'x', 'driver' => 'local', 'settings' => ['root' => storage_path('app/vaults/store-tests/x')],
        ])->assertNotFound();
    }

    public function test_admin_registers_an_s3_store_and_credentials_never_come_back(): void
    {
        $this->enable();

        $response = $this->actingAs($this->admin)->postJson("/api/admin/tenants/{$this->tenant->id}/vault-stores", [
            'name' => 'R2',
            'driver' => 's3',
            'settings' => ['endpoint' => 'https://s3.example.com', 'bucket' => 'acme-vaults', 'region' => 'auto', 'unexpected' => 'dropped'],
            'credentials' => ['access_key_id' => 'AKIAEXAMPLE', 'secret_access_key' => self::SECRET],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.driver', 's3')
            ->assertJsonPath('data.has_credentials', true)
            ->assertJsonPath('data.status', 'untested')
            ->assertJsonMissingPath('data.credentials')
            ->assertJsonMissingPath('data.settings.unexpected');
        $this->assertStringNotContainsString(self::SECRET, $response->getContent());

        $raw = (string) DB::table('vault_stores')->value('credentials');
        $this->assertStringNotContainsString(self::SECRET, $raw);
        $this->assertSame(self::SECRET, TenantVaultStore::query()->first()->credentials['secret_access_key']);

        $list = $this->actingAs($this->admin)->getJson("/api/admin/tenants/{$this->tenant->id}/vault-stores")->assertOk();
        $this->assertStringNotContainsString(self::SECRET, $list->getContent());
        $this->assertStringNotContainsString(self::SECRET, json_encode(AuditLog::query()->get()->toArray()));
        $this->assertDatabaseHas('audit_log', ['event' => 'vault_store.created']);
    }

    public function test_s3_endpoints_must_be_public_https(): void
    {
        $this->enable();
        $cases = [
            'http://s3.example.com' => 'https',
            'https://127.0.0.1' => 'loopback',
            'https://10.1.2.3' => 'private',
            'https://169.254.169.254' => 'link-local metadata',
            'https://evil.example.com' => 'DNS to private',
            'https://[::ffff:192.168.0.1]' => 'IPv4-mapped IPv6',
            'https://user:pw@s3.example.com' => 'userinfo',
        ];

        foreach ($cases as $endpoint => $label) {
            $this->actingAs($this->admin)->postJson("/api/admin/tenants/{$this->tenant->id}/vault-stores", [
                'name' => 'S3 '.$label,
                'driver' => 's3',
                'settings' => ['endpoint' => $endpoint, 'bucket' => 'bucket'],
                'credentials' => ['access_key_id' => 'a', 'secret_access_key' => 'b'],
            ])->assertStatus(422)->assertJsonValidationErrors(['settings.endpoint']);
        }

        $this->assertSame(0, TenantVaultStore::query()->count());
    }

    public function test_test_connection_round_trips_a_local_store_and_reports_unimplemented_drivers(): void
    {
        $this->enable();
        $root = storage_path('app/vaults/store-tests/disk-b');

        $local = $this->actingAs($this->admin)->postJson("/api/admin/tenants/{$this->tenant->id}/vault-stores", [
            'name' => 'Disk B', 'driver' => 'local', 'settings' => ['root' => $root],
        ])->assertCreated()->json('data.id');

        $this->actingAs($this->admin)->postJson("/api/admin/vault-stores/{$local}/test")
            ->assertOk()->assertJsonPath('data.status', 'ok')->assertJsonPath('data.last_error', null);
        $this->assertSame([], array_values(array_diff(scandir($root), ['.', '..'])), 'The probe file is removed.');

        $s3 = TenantVaultStore::create([
            'tenant_id' => $this->tenant->id, 'name' => 'S3', 'driver' => 's3',
            'settings' => ['endpoint' => 'https://s3.example.com', 'bucket' => 'b'],
            'credentials' => ['access_key_id' => 'a', 'secret_access_key' => self::SECRET],
        ]);
        $failed = $this->actingAs($this->admin)->postJson("/api/admin/vault-stores/{$s3->id}/test")
            ->assertStatus(422)->assertJsonPath('data.status', 'failed');
        $this->assertStringContainsString('not available', (string) $failed->json('data.last_error'));
        $this->assertStringNotContainsString(self::SECRET, $failed->getContent());
        $this->assertDatabaseHas('audit_log', ['event' => 'vault_store.tested']);
    }

    public function test_only_instance_admins_and_tenant_owners_or_admins_manage_stores(): void
    {
        $this->enable();
        $editor = User::factory()->create(['is_admin' => false]);
        $owner = User::factory()->create(['is_admin' => false]);
        Membership::create(['tenant_id' => $this->tenant->id, 'workspace_id' => null, 'subject_id' => (string) $editor->id, 'role' => 'editor']);
        Membership::create(['tenant_id' => $this->tenant->id, 'workspace_id' => null, 'subject_id' => (string) $owner->id, 'role' => 'owner']);

        $this->actingAs($editor)->getJson("/api/admin/tenants/{$this->tenant->id}/vault-stores")->assertForbidden();
        $this->actingAs($owner)->getJson("/api/admin/tenants/{$this->tenant->id}/vault-stores")->assertOk();

        $other = Tenant::create(['slug' => 'other', 'name' => 'Other']);
        $this->actingAs($owner)->getJson("/api/admin/tenants/{$other->id}/vault-stores")->assertForbidden();
    }

    public function test_update_replaces_credentials_and_delete_refuses_a_store_in_use(): void
    {
        $this->enable();
        $store = TenantVaultStore::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Disk', 'driver' => 'local',
            'settings' => ['root' => storage_path('app/vaults/store-tests/disk')], 'status' => 'ok',
        ]);

        $this->actingAs($this->admin)->putJson("/api/admin/vault-stores/{$store->id}", ['name' => 'Disk 2'])
            ->assertOk()->assertJsonPath('data.name', 'Disk 2')->assertJsonPath('data.status', 'untested');
        $this->assertDatabaseHas('audit_log', ['event' => 'vault_store.updated']);

        Workspace::create([
            'tenant_id' => $this->tenant->id, 'slug' => 'ws', 'name' => 'WS',
            'vault_path' => storage_path('app/vaults/store-tests/ws'),
        ])->forceFill(['vault_store_id' => $store->id, 'vault_prefix' => 'ws'])->save();

        $this->actingAs($this->admin)->deleteJson("/api/admin/vault-stores/{$store->id}")->assertStatus(409);
        $this->actingAs($this->admin)->putJson("/api/admin/vault-stores/{$store->id}", ['settings' => ['root' => storage_path('app/vaults/store-tests/elsewhere')]])
            ->assertStatus(422);

        Workspace::query()->update(['vault_store_id' => null]);
        $this->actingAs($this->admin)->deleteJson("/api/admin/vault-stores/{$store->id}")->assertOk();
        $this->assertDatabaseHas('audit_log', ['event' => 'vault_store.deleted']);
    }

    public function test_local_store_roots_stay_inside_the_vault_base_path(): void
    {
        $this->enable();

        $this->actingAs($this->admin)->postJson("/api/admin/tenants/{$this->tenant->id}/vault-stores", [
            'name' => 'Etc', 'driver' => 'local', 'settings' => ['root' => '/etc/jotter-store'],
        ])->assertStatus(422)->assertJsonValidationErrors(['settings.root']);
    }

    private function enable(): void
    {
        config(['jotter.vault_stores.drivers' => ['local', 's3', 'github']]);
    }
}
