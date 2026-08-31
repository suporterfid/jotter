<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TenantPlanCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_plan_starts_a_trial_and_records_an_audit_row(): void
    {
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);

        $this->artisan('tenant:plan acme --trial-days=14 --seats=5 --name=Starter')
            ->expectsOutputToContain('Tenant acme (Acme) updated.')
            ->assertSuccessful();

        $tenant->refresh();
        $this->assertSame('trial', $tenant->plan_status);
        $this->assertSame(5, $tenant->plan_seats);
        $this->assertSame('Starter', $tenant->plan_name);
        $this->assertTrue($tenant->trial_ends_at->between(now()->addDays(13), now()->addDays(15)));
        $this->assertSame(1, AuditLog::query()->where('event', 'tenant.plan_changed')->where('tenant_id', $tenant->id)->count());
    }

    public function test_tenant_plan_moves_through_every_status_and_clears_the_trial_deadline(): void
    {
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'plan_status' => 'trial', 'trial_ends_at' => now()->addDay()]);

        foreach (['active', 'past_due', 'read_only', 'self_hosted'] as $status) {
            $this->artisan("tenant:plan acme --status={$status}")->assertSuccessful();
            $this->assertSame($status, $tenant->fresh()->plan_status);
            $this->assertNull($tenant->fresh()->trial_ends_at);
        }

        $this->artisan('tenant:plan acme --status=trial --trial-days=3')->assertSuccessful();
        $this->assertSame('trial', $tenant->fresh()->plan_status);
        $this->assertNotNull($tenant->fresh()->trial_ends_at);
    }

    public function test_tenant_plan_rejects_invalid_input(): void
    {
        Tenant::create(['slug' => 'acme', 'name' => 'Acme']);

        $this->artisan('tenant:plan acme --status=platinum')->assertFailed();
        $this->artisan('tenant:plan acme --trial-days=0')->assertFailed();
        $this->artisan('tenant:plan acme')->assertFailed();
        $this->artisan('tenant:plan missing --status=active')->assertFailed();
    }

    public function test_tenant_plan_and_tenant_show_emit_json(): void
    {
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);
        $tenant->workspaces()->create(['slug' => 'docs', 'name' => 'Docs', 'vault_path' => storage_path('app/vaults/acme-docs')]);

        // The JSON report is one write, so decode the captured output instead of
        // chaining expectsOutputToContain (which expects separate writes).
        $this->assertSame(0, Artisan::call('tenant:plan', ['slug' => 'acme', '--status' => 'active', '--seats' => '3', '--json' => true]));
        $plan = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('acme', $plan['slug']);
        $this->assertSame('active', $plan['plan']['status']);
        $this->assertSame(3, $plan['plan']['seats']);
        $this->assertFalse($plan['plan']['read_only']);

        $this->assertSame(0, Artisan::call('tenant:show', ['slug' => 'acme', '--json' => true]));
        $show = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('acme', $show['slug']);
        $this->assertSame(0, $show['plan']['seats_used']);
        $this->assertSame(3, $show['plan']['seats']);
        $this->assertSame('docs', $show['workspaces'][0]['slug']);

        $this->artisan('tenant:show acme')
            ->expectsOutputToContain('Tenant acme — Acme')
            ->expectsOutputToContain('active')
            ->assertSuccessful();

        $this->artisan('tenant:show missing')->assertFailed();
    }

    public function test_expire_trials_moves_only_overdue_trials_to_read_only(): void
    {
        $expired = Tenant::create(['slug' => 'expired', 'name' => 'Expired', 'plan_status' => 'trial', 'trial_ends_at' => now()->subMinute()]);
        $running = Tenant::create(['slug' => 'running', 'name' => 'Running', 'plan_status' => 'trial', 'trial_ends_at' => now()->addDay()]);
        $active = Tenant::create(['slug' => 'active', 'name' => 'Active', 'plan_status' => 'active']);
        $selfHosted = Tenant::create(['slug' => 'self', 'name' => 'Self']);

        $this->artisan('tenant:expire-trials')
            ->expectsOutputToContain('Expired 1 trial(s): expired.')
            ->assertSuccessful();

        $this->assertSame('read_only', $expired->fresh()->plan_status);
        $this->assertSame('trial', $running->fresh()->plan_status);
        $this->assertSame('active', $active->fresh()->plan_status);
        $this->assertSame('self_hosted', $selfHosted->fresh()->plan_status);
        $this->assertSame(1, AuditLog::query()->where('event', 'tenant.trial_expired')->where('tenant_id', $expired->id)->count());

        // Idempotent: a second run finds nothing.
        $this->artisan('tenant:expire-trials')->expectsOutputToContain('Expired 0 trial(s).')->assertSuccessful();
    }
}
