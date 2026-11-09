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

    private function logAt(AuditEvent $event, int $daysAgo): void
    {
        AuditLog::withoutEvents(function () use ($event, $daysAgo): void {
            $log = AuditLog::query()->create([
                'tenant_id' => null,
                'workspace_id' => null,
                'actor_subject_id' => null,
                'actor_type' => 'system',
                'event' => $event->value,
            ]);
            \Illuminate\Support\Facades\DB::table('audit_log')->where('id', $log->id)->update(['created_at' => Carbon::now()->subDays($daysAgo)]);
        });
    }

    private function rows(AuditEvent $event): int
    {
        return AuditLog::query()->where('event', $event->value)->count();
    }

    public function test_access_records_can_be_kept_longer_than_other_audit_events(): void
    {
        config(['jotter.audit.retention_days' => 90, 'jotter.audit.access_retention_days' => 184]);
        $this->logAt(AuditEvent::AUTH_LOGIN_SUCCESS, 100);   // access record, inside 184 days: kept
        $this->logAt(AuditEvent::OAUTH_TOKEN_ISSUED, 150);   // access record, inside 184 days: kept
        $this->logAt(AuditEvent::AUTH_LOGOUT, 200);          // access record, past 184 days: pruned
        $this->logAt(AuditEvent::NOTE_CREATED, 100);         // other event, past 90 days: pruned
        $this->logAt(AuditEvent::NOTE_UPDATED, 10);          // other event, recent: kept

        $this->artisan('audit:prune')->assertExitCode(0);

        $this->assertSame(1, $this->rows(AuditEvent::AUTH_LOGIN_SUCCESS));
        $this->assertSame(1, $this->rows(AuditEvent::OAUTH_TOKEN_ISSUED));
        $this->assertSame(0, $this->rows(AuditEvent::AUTH_LOGOUT));
        $this->assertSame(0, $this->rows(AuditEvent::NOTE_CREATED));
        $this->assertSame(1, $this->rows(AuditEvent::NOTE_UPDATED));

        $prune = AuditLog::query()->where('event', AuditEvent::SYSTEM_AUDIT_PRUNED->value)->sole();
        $this->assertSame(90, $prune->metadata['retention_days']);
        $this->assertSame(184, $prune->metadata['access_retention_days']);
        $this->assertSame(1, $prune->metadata['access_rows_pruned']);
    }

    public function test_by_default_access_records_follow_the_audit_window(): void
    {
        config(['jotter.audit.retention_days' => 90, 'jotter.audit.access_retention_days' => null]);
        $this->logAt(AuditEvent::AUTH_LOGIN_SUCCESS, 100);

        $this->artisan('audit:prune')->assertExitCode(0);

        $this->assertSame(0, $this->rows(AuditEvent::AUTH_LOGIN_SUCCESS));
    }

    public function test_the_access_window_is_never_shorter_than_the_audit_window(): void
    {
        $this->logAt(AuditEvent::AUTH_LOGIN_SUCCESS, 100);

        $this->artisan('audit:prune', ['--days' => 120, '--access-days' => 30])->assertExitCode(0);

        $this->assertSame(1, $this->rows(AuditEvent::AUTH_LOGIN_SUCCESS));
    }
}
