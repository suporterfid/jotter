<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/** `jotter:install`: the standard first run of a new installation (and harmless on upgrades). */
final class JotterInstallCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $vaultBase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->vaultBase = storage_path('framework/testing/install-'.uniqid());
        File::ensureDirectoryExists($this->vaultBase);
        config([
            'vault.base_path' => $this->vaultBase,
            'app.url' => 'https://brain.example.com',
            'jotter.seed.tenant_name' => 'Brain',
            'jotter.seed.tenant_slug' => 'brain',
            'jotter.seed.workspace_name' => 'Notas',
            'jotter.seed.workspace_slug' => 'notas',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->vaultBase);
        parent::tearDown();
    }

    private function install(array $options): array
    {
        $output = new BufferedOutput;
        $exit = Artisan::call('jotter:install', $options, $output);

        return [$exit, $output->fetch()];
    }

    public function test_the_first_run_needs_an_admin_email(): void
    {
        [$exit, $output] = $this->install([]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('--admin-email', $output);
        $this->assertSame(0, Tenant::query()->count());
    }

    public function test_the_first_run_creates_the_organization_workspace_and_admin_from_the_env_defaults(): void
    {
        $file = $this->vaultBase.'/admin-password';
        [$exit, $output] = $this->install(['--admin-email' => 'owner@example.com', '--password-file' => $file]);

        $this->assertSame(0, $exit, $output);
        $tenant = Tenant::query()->sole();
        $this->assertSame(['brain', 'Brain', 'active'], [$tenant->slug, $tenant->name, $tenant->plan_status]);
        $workspace = Workspace::query()->sole();
        $this->assertSame(['notas', $this->vaultBase.'/notas'], [$workspace->slug, $workspace->vault_path]);
        $this->assertDirectoryExists($this->vaultBase.'/notas');

        $admin = User::query()->sole();
        $this->assertTrue((bool) $admin->is_admin);
        $this->assertSame('owner', $admin->name);
        $this->assertTrue(Membership::query()->where('tenant_id', $tenant->id)->where('role', 'owner')->exists());

        $password = trim((string) file_get_contents($file));
        $this->assertSame('600', substr(sprintf('%o', fileperms($file)), -3));
        $this->assertTrue(Hash::check($password, $admin->password));
        $this->assertStringNotContainsString($password, $output, 'The password goes only to the file.');
        $this->assertStringContainsString('* * * * * '.PHP_BINARY.' '.base_path('artisan').' schedule:run', $output);
        $this->assertStringContainsString('First organization and administrator', $output);
    }

    public function test_running_it_again_creates_nothing_and_an_existing_admin_keeps_the_password(): void
    {
        $existing = User::factory()->create(['email' => 'owner@example.com', 'is_admin' => true, 'password' => Hash::make('kept-password-123')]);

        [$exit, $output] = $this->install(['--admin-email' => 'owner@example.com', '--tenant-slug' => 'acme', '--tenant-name' => 'Acme']);
        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('existing user, password unchanged', $output);
        $this->assertTrue(Hash::check('kept-password-123', $existing->fresh()->password));
        $this->assertSame('acme', Tenant::query()->sole()->slug);

        [$exit, $output] = $this->install([]);
        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('Already installed', $output);
        $this->assertSame(1, Tenant::query()->count());
        $this->assertSame(1, User::query()->count());
    }

    public function test_it_refuses_to_overwrite_an_existing_password_file(): void
    {
        $file = $this->vaultBase.'/taken';
        file_put_contents($file, 'x');

        [$exit, $output] = $this->install(['--admin-email' => 'owner@example.com', '--password-file' => $file]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('already exists', $output);
        $this->assertSame(0, Tenant::query()->count());
        $this->assertSame('x', file_get_contents($file));
    }
}
