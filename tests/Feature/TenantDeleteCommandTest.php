<?php

namespace Tests\Feature;

use App\Domain\Audit\AuditEvent;
use App\Models\AuditLog;
use App\Models\Membership;
use App\Models\Note;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use App\Domain\Vault\VaultStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * `tenant:delete` replaces the manual tinker runbook for removing a hosted
 * customer (LGPD block 3).
 */
final class TenantDeleteCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $base;
    private Tenant $acme;
    private Tenant $other;
    private Workspace $wiki;
    private User $onlyAcme;
    private User $both;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->base = sys_get_temp_dir().'/jotter-tenant-delete-'.uniqid('', true);
        File::ensureDirectoryExists($this->base.'/acme-wiki');
        File::ensureDirectoryExists($this->base.'/other-main');
        config(['vault.base_path' => $this->base]);

        $this->acme = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);
        $this->other = Tenant::create(['slug' => 'other', 'name' => 'Other']);
        $this->wiki = Workspace::create(['tenant_id' => $this->acme->id, 'slug' => 'wiki', 'name' => 'Wiki', 'vault_path' => $this->base.'/acme-wiki']);
        $main = Workspace::create(['tenant_id' => $this->other->id, 'slug' => 'main', 'name' => 'Main', 'vault_path' => $this->base.'/other-main']);

        $this->onlyAcme = User::factory()->create(['email' => 'only@acme.test', 'is_admin' => false]);
        $this->both = User::factory()->create(['email' => 'both@acme.test', 'is_admin' => false]);
        $this->admin = User::factory()->create(['email' => 'admin@host.test', 'is_admin' => true]);
        foreach ([$this->onlyAcme, $this->both, $this->admin] as $user) {
            Membership::create(['tenant_id' => $this->acme->id, 'workspace_id' => $this->wiki->id, 'subject_id' => (string) $user->id, 'role' => 'editor']);
        }
        Membership::create(['tenant_id' => $this->other->id, 'workspace_id' => $main->id, 'subject_id' => (string) $this->both->id, 'role' => 'viewer']);

        app(VaultStorage::class)->write($this->wiki, 'cliente.md', "# Cliente\n");

        AuditLog::withoutEvents(function (): void {
            AuditLog::query()->create(['tenant_id' => $this->acme->id, 'workspace_id' => $this->wiki->id, 'actor_subject_id' => (string) $this->both->id, 'actor_type' => 'user', 'event' => AuditEvent::AUTH_LOGIN_SUCCESS->value]);
            AuditLog::query()->create(['tenant_id' => $this->acme->id, 'workspace_id' => $this->wiki->id, 'actor_subject_id' => (string) $this->both->id, 'actor_type' => 'user', 'event' => AuditEvent::NOTE_CREATED->value]);
            AuditLog::query()->create(['tenant_id' => $this->other->id, 'workspace_id' => null, 'actor_subject_id' => (string) $this->both->id, 'actor_type' => 'user', 'event' => AuditEvent::NOTE_CREATED->value]);
        });
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->base);
        parent::tearDown();
    }

    public function test_it_deletes_the_tenant_its_vaults_and_the_accounts_that_only_belong_to_it(): void
    {
        $this->artisan('tenant:delete', ['slug' => 'acme', '--force' => true])->assertSuccessful();

        $this->assertNull(Tenant::query()->find($this->acme->id));
        $this->assertNull(Workspace::query()->find($this->wiki->id));
        $this->assertSame(0, Note::withTrashed()->where('workspace_id', $this->wiki->id)->count());
        $this->assertDirectoryDoesNotExist($this->base.'/acme-wiki');
        $this->assertDirectoryExists($this->base.'/other-main');

        $this->assertNull(User::query()->find($this->onlyAcme->id), 'member of this tenant only: deleted');
        $this->assertNotNull(User::query()->find($this->both->id), 'member elsewhere: kept');
        $this->assertNotNull(User::query()->find($this->admin->id), 'administrators are never deleted with a tenant');
        $this->assertSame(0, Membership::query()->where('tenant_id', $this->acme->id)->count());
        $this->assertSame(1, Membership::query()->where('subject_id', (string) $this->both->id)->count());

        // Access record kept (Marco Civil) but detached; the rest of the tenant's audit history is gone.
        $access = DB::table('audit_log')->where('event', AuditEvent::AUTH_LOGIN_SUCCESS->value)->get();
        $this->assertCount(1, $access);
        $this->assertNull($access[0]->tenant_id);
        $this->assertNull($access[0]->workspace_id);
        $this->assertSame(1, DB::table('audit_log')->where('event', AuditEvent::NOTE_CREATED->value)->count(), 'the other tenant keeps its history');
        $this->assertSame(1, DB::table('audit_log')->where('event', AuditEvent::NOTE_CREATED->value)->where('tenant_id', $this->other->id)->count());

        $record = AuditLog::query()->where('event', AuditEvent::TENANT_DELETED->value)->sole();
        $this->assertSame('acme', $record->metadata['tenant']);
        $this->assertSame(1, $record->metadata['users_deleted']);
        $this->assertSame(1, $record->metadata['access_records_kept']);
        $this->assertSame(1, $record->metadata['vaults_removed']);
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $this->artisan('tenant:delete', ['slug' => 'acme', '--dry-run' => true])
            ->expectsOutputToContain('only@acme.test')
            ->assertSuccessful();

        $this->assertNotNull(Tenant::query()->find($this->acme->id));
        $this->assertNotNull(User::query()->find($this->onlyAcme->id));
        $this->assertDirectoryExists($this->base.'/acme-wiki');
    }

    public function test_declining_the_confirmation_changes_nothing(): void
    {
        $this->artisan('tenant:delete', ['slug' => 'acme'])
            ->expectsConfirmation('Delete tenant acme for good? This cannot be undone.', 'no')
            ->assertFailed();

        $this->assertNotNull(Tenant::query()->find($this->acme->id));
    }

    public function test_the_only_tenant_and_unknown_slugs_are_refused(): void
    {
        $this->artisan('tenant:delete', ['slug' => 'nope', '--force' => true])->assertFailed();

        $this->artisan('tenant:delete', ['slug' => 'other', '--force' => true])->assertSuccessful();
        $this->artisan('tenant:delete', ['slug' => 'acme', '--force' => true])
            ->expectsOutputToContain('only tenant')
            ->assertFailed();
        $this->assertNotNull(Tenant::query()->find($this->acme->id));
    }

    public function test_a_vault_outside_the_base_path_is_never_removed_from_disk(): void
    {
        $outside = sys_get_temp_dir().'/jotter-outside-'.uniqid('', true);
        File::ensureDirectoryExists($outside);
        $this->wiki->forceFill(['vault_path' => $outside])->save();

        try {
            $this->artisan('tenant:delete', ['slug' => 'acme', '--force' => true])
                ->expectsOutputToContain('not removed')
                ->assertSuccessful();
            $this->assertDirectoryExists($outside);
            $this->assertNull(Tenant::query()->find($this->acme->id));
        } finally {
            File::deleteDirectory($outside);
        }
    }

    public function test_it_can_export_the_tenant_first(): void
    {
        $zip = $this->base.'/exports/acme.zip';

        $this->artisan('tenant:delete', ['slug' => 'acme', '--force' => true, '--export-to' => $zip])->assertSuccessful();

        $this->assertFileExists($zip);
        $this->assertGreaterThan(0, filesize($zip));
        $this->assertNull(Tenant::query()->find($this->acme->id));
    }
}
