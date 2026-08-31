<?php

namespace Tests\Feature;

use App\Mail\WelcomeEmail;
use App\Models\AuditLog;
use App\Models\Membership;
use App\Models\Note;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

final class TenantProvisionCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $vaultBase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vaultBase = storage_path('framework/testing/provision-'.uniqid());
        File::ensureDirectoryExists($this->vaultBase);
        config(['vault.base_path' => $this->vaultBase, 'mail.default' => 'array', 'mail.from.address' => 'no-reply@acme.example', 'app.url' => 'https://acme.example.com']);
        Mail::fake();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->vaultBase);

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function provisionOptions(array $overrides = []): array
    {
        return array_merge([
            '--tenant-name' => 'Acme Ltda',
            '--tenant-slug' => 'acme',
            '--workspace-name' => 'Acme Docs',
            '--workspace-slug' => 'acme-docs',
            '--admin-email' => 'Owner@Acme.example',
            '--admin-name' => 'Ana Owner',
            '--trial-days' => '14',
            '--seats' => '5',
            '--locale' => 'pt-BR',
            '--json' => true,
        ], $overrides);
    }

    /**
     * The provisioner calls `platform:bootstrap-admin` through Artisan, which
     * replaces Artisan::output()'s buffer; capture the outer output explicitly.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function runProvision(array $options): array
    {
        $output = new BufferedOutput;
        $this->assertSame(0, Artisan::call('tenant:provision', $options, $output));

        return json_decode($output->fetch(), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_provision_creates_everything_and_prints_the_password_once(): void
    {
        $report = $this->runProvision($this->provisionOptions());

        $tenant = Tenant::query()->where('slug', 'acme')->firstOrFail();
        $this->assertSame('trial', $tenant->plan_status);
        $this->assertSame(5, $tenant->plan_seats);
        $this->assertTrue($tenant->trial_ends_at->between(now()->addDays(13), now()->addDays(15)));

        $workspace = Workspace::query()->where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertSame('acme-docs', $workspace->slug);
        $this->assertSame(realpath($this->vaultBase.'/acme-docs'), realpath($workspace->vault_path));
        $this->assertDirectoryExists($this->vaultBase.'/acme-docs/_templates');

        $admin = User::query()->where('email', 'owner@acme.example')->firstOrFail();
        $this->assertTrue($admin->is_admin);
        $this->assertSame('Ana Owner', $admin->name);
        $this->assertSame('pt-BR', $admin->locale);
        $this->assertTrue(Membership::query()->where('tenant_id', $tenant->id)->where('subject_id', (string) $admin->id)->where('role', 'owner')->exists());

        // Templates installed in the requested locale and projected as notes.
        $this->assertCount(5, $report['templates_installed']);
        $this->assertContains('_templates/adr.md', $report['templates_installed']);
        $this->assertSame(5, Note::query()->where('workspace_id', $workspace->id)->where('path', 'like', '_templates/%')->count());
        $this->assertStringContainsString('## Contexto', (string) file_get_contents($workspace->vault_path.'/_templates/adr.md'));

        // Password: strong, valid, present in the report, absent from audit and logs.
        $password = $report['admin']['password'];
        $this->assertIsString($password);
        $this->assertGreaterThanOrEqual(20, strlen($password));
        $this->assertTrue(Hash::check($password, $admin->fresh()->password));
        $this->assertSame(1, AuditLog::query()->where('event', 'tenant.provisioned')->count());
        $this->assertStringNotContainsString($password, json_encode(AuditLog::query()->get()->toArray()));

        $this->assertSame('https://acme.example.com/api/webdav/'.$workspace->id, $report['urls']['webdav']);
        $this->assertTrue($report['welcome_email']['sent']);
        Mail::assertSent(WelcomeEmail::class, function (WelcomeEmail $mail) use ($password): bool {
            $html = $mail->render();

            return $mail->hasTo('owner@acme.example')
                && $mail->locale === 'pt-BR'
                && str_contains($html, 'Bem-vindo')
                && str_contains($html, 'Guia do MCP')
                && ! str_contains($html, $password);
        });
    }

    public function test_provision_is_not_repeated_for_an_existing_tenant(): void
    {
        Tenant::create(['slug' => 'acme', 'name' => 'Existing']);

        $this->artisan('tenant:provision', $this->provisionOptions(['--json' => false]))
            ->expectsOutputToContain('Tenant [acme] already exists.')
            ->assertExitCode(2);

        $this->assertSame(0, Workspace::query()->count());
        $this->assertSame(0, User::query()->count());
        $this->assertDirectoryDoesNotExist($this->vaultBase.'/acme-docs');
        Mail::assertNothingSent();
    }

    public function test_provision_reuses_an_existing_admin_without_touching_the_password(): void
    {
        $existing = User::factory()->create(['email' => 'owner@acme.example', 'password' => Hash::make('keep-this-password'), 'locale' => 'en']);

        $report = $this->runProvision($this->provisionOptions(['--locale' => 'en']));

        $this->assertFalse($report['admin']['created']);
        $this->assertNull($report['admin']['password']);
        $this->assertTrue(Hash::check('keep-this-password', $existing->fresh()->password));
        $this->assertTrue(Membership::query()->where('subject_id', (string) $existing->id)->where('role', 'owner')->exists());
        $this->assertStringContainsString('## Context', (string) file_get_contents(Workspace::query()->firstOrFail()->vault_path.'/_templates/adr.md'));
    }

    public function test_provision_validates_input_and_requires_every_identity_option(): void
    {
        $this->artisan('tenant:provision', ['--tenant-name' => 'Acme'])->expectsOutputToContain('--tenant-slug is required.')->assertFailed();
        $this->artisan('tenant:provision', $this->provisionOptions(['--admin-email' => 'not-an-email', '--json' => false]))->assertFailed();

        $this->assertSame(0, Tenant::query()->count());
    }

    public function test_provision_without_trial_creates_an_active_tenant_and_can_skip_the_welcome_email(): void
    {
        $report = $this->runProvision($this->provisionOptions(['--trial-days' => '0', '--seats' => '0', '--no-welcome-email' => true]));

        $this->assertSame('active', $report['plan']['status']);
        $this->assertNull($report['plan']['trial_ends_at']);
        $this->assertNull($report['plan']['seats']);
        $this->assertFalse($report['welcome_email']['sent']);
        Mail::assertNothingSent();
    }

    public function test_provision_reports_a_missing_sender_instead_of_failing(): void
    {
        config(['mail.from.address' => '']);

        $report = $this->runProvision($this->provisionOptions());

        $this->assertFalse($report['welcome_email']['sent']);
        $this->assertStringContainsString('MAIL_FROM_ADDRESS is not set', $report['welcome_email']['error']);
        $this->assertSame(1, Tenant::query()->count());
        Mail::assertNothingSent();
    }
}
