<?php

namespace Tests\Feature;

use App\Domain\Privacy\ErasureLog;
use App\Domain\Privacy\ErasureReplay;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * LGPD block 4c: the erasure log (tombstones of deleted tenants and accounts).
 */
final class ErasureLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_and_tenant_deletion_write_tombstones_without_personal_data(): void
    {
        $base = sys_get_temp_dir().'/jotter-erasure-'.uniqid('', true);
        File::ensureDirectoryExists($base.'/acme');
        config(['vault.base_path' => $base]);
        try {
            $acme = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);
            Tenant::create(['slug' => 'other', 'name' => 'Other']);
            $workspace = Workspace::create(['tenant_id' => $acme->id, 'slug' => 'wiki', 'name' => 'Wiki', 'vault_path' => $base.'/acme']);
            $member = User::factory()->create(['email' => 'only@acme.test']);
            Membership::create(['tenant_id' => $acme->id, 'workspace_id' => $workspace->id, 'subject_id' => (string) $member->id, 'role' => 'editor']);
            $loner = User::factory()->create(['email' => 'maria@example.com', 'name' => 'Maria Souza']);

            $this->artisan('user:delete', ['email' => 'maria@example.com', '--force' => true])->assertSuccessful();
            $this->artisan('tenant:delete', ['slug' => 'acme', '--force' => true])->assertSuccessful();

            $entries = (new ErasureLog)->entries();
            $this->assertSame(['user', 'user', 'tenant'], array_column($entries, 'type'));
            $this->assertSame([$loner->id, $member->id, $acme->id], array_column($entries, 'id'));
            $this->assertSame('acme', $entries[2]['slug']);
            $this->assertSame($loner->created_at->toIso8601String(), $entries[0]['created_at']);

            $raw = (string) file_get_contents(ErasureLog::path());
            $this->assertStringNotContainsString('maria@example.com', $raw);
            $this->assertStringNotContainsString('Maria Souza', $raw);
            $this->assertStringNotContainsString('only@acme.test', $raw);
        } finally {
            File::deleteDirectory($base);
        }
    }

    public function test_recording_the_same_row_twice_keeps_one_entry_and_junk_lines_are_ignored(): void
    {
        $log = new ErasureLog;
        $log->record('user', 7, '2026-01-01T00:00:00+00:00', 'cli');
        $log->record('user', 7, '2026-01-01T00:00:00+00:00', 'cli');
        $log->record('user', 7, '2026-05-01T00:00:00+00:00', 'cli');
        file_put_contents(ErasureLog::path(), "not json\n{\"type\":\"planet\",\"id\":1}\n", FILE_APPEND);

        $this->assertCount(2, $log->entries(), 'same id, another created_at: another row');
    }

    public function test_merge_keeps_the_earliest_entry_per_row(): void
    {
        $a = [['type' => 'user', 'id' => 1, 'created_at' => 'x', 'deleted_at' => '2026-02-01T00:00:00+00:00']];
        $b = [
            ['type' => 'user', 'id' => 1, 'created_at' => 'x', 'deleted_at' => '2026-01-01T00:00:00+00:00'],
            ['type' => 'tenant', 'id' => 1, 'created_at' => 'y', 'deleted_at' => '2026-03-01T00:00:00+00:00'],
        ];

        $merged = ErasureLog::merge($a, $b);

        $this->assertCount(2, $merged);
        $this->assertSame('2026-01-01T00:00:00+00:00', $merged[0]['deleted_at']);
    }

    public function test_replay_never_deletes_a_new_row_that_reused_a_deleted_id(): void
    {
        $user = User::factory()->create(['email' => 'nova@example.com']);
        $tombstone = ['type' => 'user', 'id' => $user->id, 'created_at' => '2020-01-01T00:00:00+00:00', 'deleted_at' => now()->toIso8601String()];

        $result = (new ErasureReplay)->replay([$tombstone]);

        $this->assertSame(0, $result['users']);
        $this->assertSame(1, $result['already_gone']);
        $this->assertNotNull(User::query()->find($user->id));
    }

    public function test_old_entries_are_pruned_by_the_nightly_command(): void
    {
        $log = new ErasureLog;
        $log->record('user', 1, null);
        $this->travel(500)->days();
        $log->record('user', 2, null);

        $this->artisan('erasure-log:prune')->assertSuccessful();

        $this->assertSame([2], array_column($log->entries(), 'id'));
    }

    public function test_doctor_reports_the_erasure_log(): void
    {
        $check = fn (): array => array_column(app(\App\Domain\Health\InstanceDoctor::class)->run()->toArray()['checks'], null, 'id')['erasure_log'];
        $this->assertSame('pass', $check()['status']);
        $this->assertFalse($check()['details']['exists']);

        (new ErasureLog)->record('tenant', 3, null, null, ['slug' => 'acme']);
        $this->assertSame(1, $check()['details']['entries']);
    }
}
