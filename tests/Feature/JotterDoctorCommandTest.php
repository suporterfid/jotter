<?php

namespace Tests\Feature;

use App\Domain\Health\InstanceDoctor;
use App\Support\SchedulerHeartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class JotterDoctorCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $vault;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vault = storage_path('framework/testing/doctor-vault-'.uniqid());
        File::ensureDirectoryExists($this->vault);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->vault);

        parent::tearDown();
    }

    private function healthyProductionConfig(): void
    {
        config([
            'app.env' => 'production',
            'app.debug' => false,
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
            'app.url' => 'https://client-a.example.com',
            'mail.default' => 'smtp',
            'mail.from.address' => 'no-reply@client-a.example.com',
            'vault.base_path' => $this->vault,
            'jotter.instance_slug' => 'client-a',
        ]);
        SchedulerHeartbeat::record();
    }

    public function test_doctor_passes_on_a_healthy_production_installation(): void
    {
        $this->healthyProductionConfig();

        $this->artisan('jotter:doctor')
            ->expectsOutputToContain('instance: client-a')
            ->expectsOutputToContain('[PASS] Scheduler heartbeat')
            ->expectsOutputToContain('[PASS] Vault outside document root')
            ->expectsOutputToContain('[PASS] Pending migrations')
            ->expectsOutputToContain('0 failure(s)')
            ->assertSuccessful();
    }

    public function test_doctor_json_report_is_machine_readable(): void
    {
        $this->healthyProductionConfig();

        $this->artisan('jotter:doctor --json')->assertSuccessful();

        // Re-run through the doctor directly to inspect the structure without console buffering.
        $report = app(InstanceDoctor::class)->run()->toArray();

        // Optional extensions (e.g. gd) may be missing in the dev image: warnings are allowed, failures are not.
        $this->assertContains($report['status'], ['ok', 'warn']);
        $this->assertSame('client-a', $report['instance']);
        $this->assertSame('production', $report['environment']);
        $this->assertSame(0, $report['summary']['failures']);
        $ids = array_column($report['checks'], 'id');
        foreach ([
            'php_version', 'php_extensions', 'app_key', 'app_debug', 'app_url_https',
            'storage_writable', 'bootstrap_cache_writable', 'vault_path', 'vault_outside_docroot',
            'vault_disk_space', 'database', 'migrations', 'mail_mailer', 'scheduler', 'instance_slug',
        ] as $expected) {
            $this->assertContains($expected, $ids);
        }
    }

    public function test_doctor_fails_when_critical_checks_fail(): void
    {
        $this->healthyProductionConfig();
        config([
            'app.key' => '',
            'app.debug' => true,
            'app.url' => 'http://client-a.example.com',
            'vault.base_path' => public_path('vault-inside-docroot'),
        ]);
        File::ensureDirectoryExists(public_path('vault-inside-docroot'));
        Cache::forget(SchedulerHeartbeat::CACHE_KEY);

        try {
            $this->artisan('jotter:doctor')
                ->expectsOutputToContain('[FAIL] APP_KEY')
                ->expectsOutputToContain('[FAIL] APP_DEBUG')
                ->expectsOutputToContain('[FAIL] APP_URL uses HTTPS')
                ->expectsOutputToContain('[FAIL] Vault outside document root')
                ->expectsOutputToContain('[FAIL] Scheduler heartbeat')
                ->expectsOutputToContain('status: FAIL')
                ->assertFailed();
        } finally {
            File::deleteDirectory(public_path('vault-inside-docroot'));
        }
    }

    public function test_doctor_treats_stale_scheduler_and_log_mailer_as_configured_severities(): void
    {
        $this->healthyProductionConfig();
        config(['mail.default' => 'log']);
        Cache::forever(SchedulerHeartbeat::CACHE_KEY, now()->subMinutes(30)->toIso8601String());

        $report = app(InstanceDoctor::class)->run()->toArray();
        $byId = array_column($report['checks'], null, 'id');

        $this->assertSame('warn', $byId['mail_mailer']['status']);
        config(['mail.from.address' => 'hello@example.com']);
        $this->assertSame('warn', array_column(app(InstanceDoctor::class)->run()->toArray()['checks'], null, 'id')['mail_from']['status']);
        $this->assertSame('fail', $byId['scheduler']['status']);
        $this->assertSame('fail', $report['status']);
    }

    public function test_doctor_only_warns_outside_production_for_debug_and_http(): void
    {
        $this->healthyProductionConfig();
        config(['app.env' => 'local', 'app.debug' => true, 'app.url' => 'http://localhost:8080']);

        $report = app(InstanceDoctor::class)->run()->toArray();
        $byId = array_column($report['checks'], null, 'id');

        $this->assertSame('warn', $byId['app_env']['status']);
        $this->assertSame('warn', $byId['app_debug']['status']);
        $this->assertSame('warn', $byId['app_url_https']['status']);
        $this->assertSame('warn', $report['status']);
    }

    public function test_doctor_reports_missing_vault_directory_as_critical(): void
    {
        $this->healthyProductionConfig();
        config(['vault.base_path' => $this->vault.'/does-not-exist']);

        $this->artisan('jotter:doctor')
            ->expectsOutputToContain('[FAIL] VAULT_BASE_PATH')
            ->assertFailed();
    }

    public function test_doctor_reports_oauth_disabled_then_checks_its_setup(): void
    {
        $this->healthyProductionConfig();
        config(['jotter.oauth.enabled' => false]);
        $byId = array_column(app(InstanceDoctor::class)->run()->toArray()['checks'], null, 'id');
        $this->assertSame('pass', $byId['oauth']['status']);
        $this->assertArrayNotHasKey('oauth_metadata', $byId);

        \Illuminate\Support\Facades\Http::fake(['https://claude.ai/*' => \Illuminate\Support\Facades\Http::response('', 200)]);
        config(['jotter.oauth.enabled' => true]);
        $byId = array_column(app(InstanceDoctor::class)->run()->toArray()['checks'], null, 'id');
        $this->assertSame('pass', $byId['oauth_cimd']['status']);
        $this->assertSame('pass', $byId['oauth_issuer']['status']);
        $this->assertSame('pass', $byId['oauth_metadata']['status']);
        $this->assertSame('pass', $byId['oauth_redirect_allowlist']['status']);
        $this->assertSame('pass', $byId['oauth_prune']['status']);

        config(['jotter.oauth.redirect_allowlist' => ['http://claude.ai/cb', 'https://chatgpt.com/x']]);
        $byId = array_column(app(InstanceDoctor::class)->run()->toArray()['checks'], null, 'id');
        $this->assertSame('fail', $byId['oauth_redirect_allowlist']['status']);
        $this->assertSame(['http://claude.ai/cb'], $byId['oauth_redirect_allowlist']['details']['invalid']);

        config(['app.url' => 'http://brain.example.com']);
        $byId = array_column(app(InstanceDoctor::class)->run()->toArray()['checks'], null, 'id');
        $this->assertSame('fail', $byId['oauth_issuer']['status']);
    }

    public function test_doctor_reports_when_outbound_https_for_client_metadata_documents_fails(): void
    {
        \Illuminate\Support\Facades\Http::fake(static fn () => throw new \Illuminate\Http\Client\ConnectionException('blocked'));
        config(['jotter.oauth.enabled' => true, 'app.url' => 'https://brain.example.com']);

        $byId = array_column(app(InstanceDoctor::class)->run()->toArray()['checks'], null, 'id');
        $this->assertSame('warn', $byId['oauth_cimd']['status']);
        $this->assertSame('warning', $byId['oauth_cimd']['severity']);

        config(['jotter.oauth.cimd.enabled' => false]);
        $byId = array_column(app(InstanceDoctor::class)->run()->toArray()['checks'], null, 'id');
        $this->assertSame('pass', $byId['oauth_cimd']['status']);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function checksById(): array
    {
        return array_column(app(InstanceDoctor::class)->run()->toArray()['checks'], null, 'id');
    }

    public function test_doctor_warns_about_insecure_session_and_unrotated_logs(): void
    {
        $this->healthyProductionConfig();
        config([
            'session.secure' => false,
            'session.encrypt' => false,
            'logging.default' => 'stack',
            'logging.channels.stack.channels' => ['single'],
        ]);

        $byId = $this->checksById();
        $this->assertSame('warn', $byId['session_security']['status']);
        $this->assertStringContainsString('SESSION_SECURE_COOKIE=true', $byId['session_security']['message']);
        $this->assertStringContainsString('SESSION_ENCRYPT=true', $byId['session_security']['message']);
        $this->assertSame('warn', $byId['log_retention']['status']);

        config([
            'session.secure' => true,
            'session.encrypt' => true,
            'logging.channels.stack.channels' => ['daily'],
            'logging.channels.daily.days' => 14,
        ]);

        $byId = $this->checksById();
        $this->assertSame('pass', $byId['session_security']['status']);
        $this->assertSame('pass', $byId['log_retention']['status']);
    }

    public function test_doctor_checks_access_record_retention_only_for_a_service_offered_to_others(): void
    {
        $this->healthyProductionConfig();
        config([
            'jotter.audit.retention_days' => 90,
            'jotter.audit.access_retention_days' => null,
            'jotter.brand.terms_url' => null,
            'jotter.brand.privacy_url' => null,
        ]);

        // Personal installation: informational pass, never a warning.
        $this->assertSame('pass', $this->checksById()['access_log_retention']['status']);

        // Published Terms/Privacy texts: a service offered to others must keep 6 months.
        config(['jotter.brand.privacy_url' => 'https://example.com/privacidade']);
        $check = $this->checksById()['access_log_retention'];
        $this->assertSame('warn', $check['status']);
        $this->assertStringContainsString('JOTTER_ACCESS_LOG_RETENTION_DAYS=184', $check['message']);

        config(['jotter.audit.access_retention_days' => 184]);
        $this->assertSame('pass', $this->checksById()['access_log_retention']['status']);
    }

    public function test_doctor_lists_administrators_without_two_step_verification(): void
    {
        $this->healthyProductionConfig();
        config(['jotter.auth_provider' => 'local']);
        $admin = \App\Models\User::factory()->create(['email' => 'root@example.com', 'is_admin' => true]);

        $check = $this->checksById()['admin_two_factor'];
        $this->assertSame('warn', $check['status']);
        $this->assertStringContainsString('root@example.com', $check['message']);

        $admin->forceFill(['two_factor_secret' => \App\Domain\Auth\TwoFactor\Totp::generateSecret(), 'two_factor_confirmed_at' => now()])->save();
        $this->assertSame('pass', $this->checksById()['admin_two_factor']['status']);

        config(['jotter.auth_provider' => 'oidc']);
        $this->assertSame('pass', $this->checksById()['admin_two_factor']['status']);
    }

    public function test_doctor_checks_backup_encryption(): void
    {
        $this->healthyProductionConfig();
        config(['jotter.backup.daily' => false, 'jotter.backup.key' => null, 'jotter.backup.previous_keys' => []]);
        $this->assertSame('pass', $this->checksById()['backup_encryption']['status']);

        config(['jotter.backup.daily' => true]);
        $check = $this->checksById()['backup_encryption'];
        $this->assertSame('warn', $check['status']);
        $this->assertStringContainsString('jotter:backup-key', $check['message']);

        config(['jotter.backup.key' => 'base64:'.base64_encode('too-short')]);
        $this->assertSame('warn', $this->checksById()['backup_encryption']['status']);

        config(['jotter.backup.key' => \App\Domain\Backup\BackupCipher::generateKey()]);
        $this->assertSame('pass', $this->checksById()['backup_encryption']['status']);
    }

    public function test_doctor_warns_about_deletion_requests_close_to_the_deadline(): void
    {
        $this->healthyProductionConfig();
        $this->assertSame('pass', $this->checksById()['deletion_requests']['status']);

        \App\Models\User::factory()->create(['email' => 'recent@example.com', 'deletion_requested_at' => now()->subDays(3)]);
        $check = $this->checksById()['deletion_requests'];
        $this->assertSame('pass', $check['status']);
        $this->assertSame(1, $check['details']['pending']);

        $old = \App\Models\User::factory()->create(['email' => 'old@example.com', 'deletion_requested_at' => now()->subDays(20)]);
        $check = $this->checksById()['deletion_requests'];
        $this->assertSame('warn', $check['status']);
        $this->assertStringContainsString('old@example.com', $check['message']);
        $this->assertSame([$old->id], $check['details']['overdue_user_ids']);
    }
}
