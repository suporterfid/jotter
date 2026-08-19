<?php

namespace Tests\Feature;

use App\Models\AuditRollup;
use App\Models\AuditRollupCursor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\QueryException;
use Tests\TestCase;

final class AuditRollupStorageTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_rollup_storage_has_a_unique_daily_dimension_key(): void
    {
        $this->assertTrue(Schema::hasTable('audit_rollups'));
        $this->assertTrue(Schema::hasTable('audit_rollup_cursors'));
        $this->assertTrue(Schema::hasColumn('audit_rollups', 'last_seen_at'));

        $cursor = AuditRollupCursor::query()->firstOrCreate(
            ['stream' => 'audit_log'],
            ['last_audit_id' => 0],
        );

        $this->assertSame(0, $cursor->last_audit_id);
    }

    public function test_audit_rollup_rejects_duplicate_daily_dimension_rows(): void
    {
        $workspace = $this->workspaceFixture();
        $attributes = [
            'workspace_id' => $workspace->id,
            'period_start' => '2026-08-19',
            'dimension' => 'note',
            'dimension_key' => '11',
        ];

        AuditRollup::query()->create($attributes + [
            'count' => 1,
            'first_seen_at' => '2026-08-19 08:00:00',
            'last_seen_at' => '2026-08-19 08:00:00',
        ]);

        $this->expectException(QueryException::class);

        AuditRollup::query()->create($attributes + [
            'count' => 1,
            'first_seen_at' => '2026-08-19 09:00:00',
            'last_seen_at' => '2026-08-19 09:00:00',
        ]);
    }

    public function test_analytics_configuration_has_bounded_defaults(): void
    {
        $this->assertSame(500, config('jotter.analytics.rollup_batch_size'));
        $this->assertSame(30, config('jotter.analytics.stale_days'));
        $this->assertFalse(config('jotter.analytics.record_reads'));
    }

    private function workspaceFixture(): object
    {
        $tenant = \App\Models\Tenant::query()->create([
            'slug' => 'analytics-'.bin2hex(random_bytes(3)),
            'name' => 'Analytics tenant',
        ]);

        return \App\Models\Workspace::query()->create([
            'tenant_id' => $tenant->id,
            'slug' => 'workspace-'.bin2hex(random_bytes(3)),
            'name' => 'Analytics workspace',
            'vault_path' => storage_path('app/vaults/analytics-'.bin2hex(random_bytes(3))),
        ]);
    }
}
