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
}
