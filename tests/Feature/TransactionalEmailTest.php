<?php

namespace Tests\Feature;

use App\Mail\PasswordResetEmail;
use App\Mail\TrialEndedEmail;
use App\Mail\TrialReminderEmail;
use App\Mail\WelcomeEmail;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class TransactionalEmailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['mail.default' => 'array', 'app.url' => 'https://acme.example.com']);
        Mail::fake();
    }

    /**
     * @return array{0: Tenant, 1: Workspace, 2: User}
     */
    private function tenantWithOwner(array $plan, string $locale = 'en'): array
    {
        $tenant = Tenant::create(array_merge(['slug' => 'acme-'.uniqid(), 'name' => 'Acme'], $plan));
        $workspace = Workspace::create(['tenant_id' => $tenant->id, 'slug' => 'docs-'.uniqid(), 'name' => 'Docs', 'vault_path' => storage_path('app/vaults/mail-'.uniqid())]);
        $owner = User::factory()->create(['locale' => $locale]);
        Membership::create(['tenant_id' => $tenant->id, 'workspace_id' => $workspace->id, 'subject_id' => (string) $owner->id, 'role' => 'owner']);
        $viewer = User::factory()->create();
        Membership::create(['tenant_id' => $tenant->id, 'workspace_id' => $workspace->id, 'subject_id' => (string) $viewer->id, 'role' => 'viewer']);

        return [$tenant, $workspace, $owner];
    }

    public function test_admin_password_reset_emails_the_user_without_the_password(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create(['locale' => 'pt-BR']);

        $this->actingAs($admin)
            ->postJson("/api/admin/users/{$user->id}/reset-password", ['new_password' => 'Brand-new-secret-99'])
            ->assertOk();

        Mail::assertSent(PasswordResetEmail::class, function (PasswordResetEmail $mail) use ($user): bool {
            $html = $mail->render();

            return $mail->hasTo($user->email)
                && $mail->locale === 'pt-BR'
                && str_contains($html, 'redefinida')
                && ! str_contains($html, 'Brand-new-secret-99');
        });
    }

    public function test_admin_password_reset_skips_mail_with_the_log_mailer(): void
    {
        config(['mail.default' => 'log']);
        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create();

        $this->actingAs($admin)->postJson("/api/admin/users/{$user->id}/reset-password", ['new_password' => 'Brand-new-secret-99'])->assertOk();

        Mail::assertNothingSent();
    }

    public function test_trial_reminder_goes_to_owners_three_days_before_the_end_only_once(): void
    {
        [$tenant, , $owner] = $this->tenantWithOwner(['plan_status' => 'trial', 'trial_ends_at' => now()->addDays(2)->addHour()], 'pt-BR');
        $this->tenantWithOwner(['plan_status' => 'trial', 'trial_ends_at' => now()->addDays(10)]);
        $this->tenantWithOwner(['plan_status' => 'active']);

        $this->artisan('tenant:expire-trials')->expectsOutputToContain('Reminded 1 trial(s): '.$tenant->slug)->assertSuccessful();

        Mail::assertSent(TrialReminderEmail::class, 1);
        Mail::assertSent(TrialReminderEmail::class, function (TrialReminderEmail $mail) use ($owner): bool {
            $html = $mail->render();

            return $mail->hasTo($owner->email) && $mail->daysLeft === 3 && str_contains($html, 'termina em 3 dias');
        });
        $this->assertNotNull($tenant->fresh()->trial_reminder_sent_at);

        $this->artisan('tenant:expire-trials')->expectsOutputToContain('Reminded 0 trial(s).')->assertSuccessful();
        Mail::assertSent(TrialReminderEmail::class, 1);
    }

    public function test_trial_end_moves_to_read_only_and_emails_owners_once(): void
    {
        [$tenant, , $owner] = $this->tenantWithOwner(['plan_status' => 'trial', 'trial_ends_at' => now()->subMinute()]);

        $this->artisan('tenant:expire-trials')
            ->expectsOutputToContain('Expired 1 trial(s): '.$tenant->slug)
            ->expectsOutputToContain('Notified 1 trial(s) ended.')
            ->assertSuccessful();

        $this->assertSame('read_only', $tenant->fresh()->plan_status);
        $this->assertNotNull($tenant->fresh()->trial_ended_notified_at);
        Mail::assertSent(TrialEndedEmail::class, function (TrialEndedEmail $mail) use ($owner): bool {
            return $mail->hasTo($owner->email) && str_contains($mail->render(), 'read-only');
        });
        Mail::assertSent(TrialEndedEmail::class, 1);

        $this->artisan('tenant:expire-trials')->expectsOutputToContain('Notified 0 trial(s) ended.')->assertSuccessful();
        Mail::assertSent(TrialEndedEmail::class, 1);
    }

    public function test_mailables_render_with_operator_branding_in_both_locales(): void
    {
        config(['jotter.brand' => ['name' => 'Cadernia', 'support_url' => 'https://cadernia.example.com/support', 'powered_by' => true]]);
        [$tenant, $workspace] = $this->tenantWithOwner(['plan_status' => 'trial', 'trial_ends_at' => now()->addDays(14)]);

        $en = (new WelcomeEmail(User::factory()->create(['locale' => 'en']), $workspace, 14))->render();
        $this->assertStringContainsString('Welcome to Cadernia', $en);
        $this->assertStringContainsString('Your trial lasts 14 days', $en);
        $this->assertStringContainsString(WelcomeEmail::MCP_GUIDE_URL, $en);
        $this->assertStringContainsString('Powered by Jotter', $en);

        $pt = (new TrialEndedEmail(User::factory()->create(['locale' => 'pt-BR']), $tenant))->render();
        $this->assertStringContainsString('terminou', $pt);
        $this->assertStringContainsString('https://cadernia.example.com/support', $pt);

        $fallback = new PasswordResetEmail(User::factory()->create(['locale' => 'de']));
        $this->assertSame('en', $fallback->locale);
    }
}
