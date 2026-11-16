<?php

namespace App\Console\Commands;

use App\Domain\Health\InstanceDoctor;
use App\Domain\Provisioning\ProvisioningRequest;
use App\Domain\Provisioning\TenantProvisioner;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * First run of a new installation, and safe to repeat after every upgrade:
 * runs the migrations and, while the installation has no organization yet,
 * creates the first organization, its workspace and the owner administrator.
 * Prints the cron entry for this PHP binary and the doctor summary.
 */
final class JotterInstallCommand extends Command
{
    protected $signature = 'jotter:install
                            {--admin-email= : E-mail of the first administrator (required on the first run)}
                            {--admin-name= : Name of the first administrator (default: the part of the e-mail before @)}
                            {--tenant-name= : Organization name (default: JOTTER_TENANT_NAME)}
                            {--tenant-slug= : Organization slug (default: JOTTER_TENANT_SLUG)}
                            {--workspace-name= : First workspace name (default: JOTTER_WORKSPACE_NAME)}
                            {--workspace-slug= : First workspace slug and vault folder (default: JOTTER_WORKSPACE_SLUG)}
                            {--locale= : Locale of the administrator and the starter templates (default: APP_LOCALE)}
                            {--password-file= : Write the generated password to this file (mode 0600) instead of printing it}
                            {--welcome-email : Also send the welcome e-mail (needs a working mailer)}';

    protected $description = 'Install or upgrade this instance: migrations, first organization, workspace and administrator, cron line, doctor.';

    public function handle(TenantProvisioner $provisioner): int
    {
        if ((string) config('app.key') === '') {
            $this->error('APP_KEY is empty. Run `php artisan key:generate` once, then run this command again.');

            return self::FAILURE;
        }

        $this->info('Migrations');
        if (Artisan::call('migrate', ['--force' => true], $this->output) !== 0) {
            $this->error('The migrations failed; fix the error above and run this command again.');

            return self::FAILURE;
        }

        $this->newLine();
        if (Tenant::query()->exists()) {
            $this->line(sprintf('Already installed: %d organization(s). Nothing to create.', Tenant::query()->count()));
        } elseif (($status = $this->provisionFirst($provisioner)) !== self::SUCCESS) {
            return $status;
        }

        $this->newLine();
        $this->info('Cron (one entry, every minute)');
        $this->line('  '.InstanceDoctor::cronLine());
        $this->comment('  Use this exact PHP binary: shared hosts often have several PHP versions.');

        $this->newLine();
        $this->info('Doctor');
        Artisan::call('jotter:doctor', [], $this->output);

        return self::SUCCESS;
    }

    private function provisionFirst(TenantProvisioner $provisioner): int
    {
        $email = trim((string) $this->option('admin-email'));
        if ($email === '') {
            $this->error('This installation has no organization yet: pass --admin-email=<you@example.com> to create the first one.');

            return self::FAILURE;
        }

        $passwordFile = trim((string) $this->option('password-file'));
        if ($passwordFile !== '' && file_exists($passwordFile)) {
            $this->error("{$passwordFile} already exists; choose a new path so no file is overwritten.");

            return self::FAILURE;
        }

        $seed = (array) config('jotter.seed');
        try {
            $result = $provisioner->provision(new ProvisioningRequest(
                tenantName: trim((string) ($this->option('tenant-name') ?: $seed['tenant_name'])),
                tenantSlug: (string) ($this->option('tenant-slug') ?: $seed['tenant_slug']),
                workspaceName: trim((string) ($this->option('workspace-name') ?: $seed['workspace_name'])),
                workspaceSlug: (string) ($this->option('workspace-slug') ?: $seed['workspace_slug']),
                adminEmail: $email,
                adminName: trim((string) ($this->option('admin-name') ?: strstr($email, '@', true) ?: $email)),
                trialDays: 0,
                seats: null,
                locale: (string) ($this->option('locale') ?: config('app.locale', 'en')),
                sendWelcomeEmail: (bool) $this->option('welcome-email'),
            ));
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('First organization');
        $this->line(sprintf('  %-12s %s (%s)', 'organization', $result->tenant->name, $result->tenant->slug));
        $this->line(sprintf('  %-12s %s — vault %s', 'workspace', $result->workspace->name, $result->workspace->vault_path));
        $this->line(sprintf('  %-12s %s (%s)', 'admin', $result->admin->email, $result->adminCreated ? 'created' : 'existing user, password unchanged'));
        $this->line(sprintf('  %-12s %s', 'sign in', $result->urls()['login'] ?? url('/')));

        if ($result->password !== null) {
            if ($passwordFile !== '') {
                $previous = umask(0077);
                $written = file_put_contents($passwordFile, $result->password.PHP_EOL);
                umask($previous);
                if ($written === false) {
                    $this->error("Could not write {$passwordFile}. The administrator exists; reset the password with `php artisan platform:bootstrap-admin`.");

                    return self::FAILURE;
                }
                chmod($passwordFile, 0600);
                $this->line(sprintf('  %-12s written to %s (mode 0600). Sign in, change it, then delete the file.', 'password', $passwordFile));
            } else {
                $this->line(sprintf('  %-12s %s', 'password', $result->password));
                $this->comment('  Shown once. It is not stored in clear text, logged, or audited. Change it after signing in.');
            }
        }

        return self::SUCCESS;
    }
}
