<?php

namespace Tests\Feature;

use App\Domain\Audit\AuditEvent;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class AuditPruneCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_prune_command_deletes_expired_rows_and_records_prune_event(): void
    {
        AuditLog::withoutEvents(function (): void {
            $log1 = AuditLog::query()->create([
                'tenant_id' => null,
                'workspace_id' => null,
                'actor_subject_id' => null,
                'actor_type' => 'system',
                'event' => AuditEvent::AUTH_LOGIN_SUCCESS->value,
            ]);
            \Illuminate\Support\Facades\DB::table('audit_log')->where('id', $log1->id)->update(['created_at' => Carbon::now()->subDays(100)]);

            $log2 = AuditLog::query()->create([
                'tenant_id' => null,
                'workspace_id' => null,
                'actor_subject_id' => null,
                'actor_type' => 'system',
                'event' => AuditEvent::AUTH_LOGIN_SUCCESS->value,
            ]);
            \Illuminate\Support\Facades\DB::table('audit_log')->where('id', $log2->id)->update(['created_at' => Carbon::now()->subDays(10)]);
        });

        $this->artisan('audit:prune', ['--days' => 90])
            ->assertExitCode(0);

        $this->assertDatabaseHas('audit_log', [
            'event' => AuditEvent::SYSTEM_AUDIT_PRUNED->value,
        ]);

        $remainingLogs = AuditLog::query()
            ->where('event', AuditEvent::AUTH_LOGIN_SUCCESS->value)
            ->get();

        $this->assertCount(1, $remainingLogs);
    }
}
