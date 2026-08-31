<?php

namespace App\Console\Commands;

use App\Domain\Provisioning\ProvisioningRequest;
use App\Domain\Provisioning\ProvisioningResult;
use App\Domain\Provisioning\TenantAlreadyExists;
use App\Domain\Provisioning\TenantProvisioner;
use Illuminate\Console\Command;

/**
 * One command per new customer, run over SSH with the host's PHP CLI. Prints
 * the generated password exactly once; it is never logged or audited.
 */
final class TenantProvisionCommand extends Command
{
    public const EXIT_ALREADY_EXISTS = 2;

    protected $signature = 'tenant:provision
                            {--tenant-name= : Display name of the customer}
                            {--tenant-slug= : URL-safe tenant identifier}
                            {--workspace-name= : Display name of the first workspace}
                            {--workspace-slug= : Slug of the first workspace; also the vault folder under VAULT_BASE_PATH}
                            {--admin-email= : E-mail of the owner administrator}
                            {--admin-name= : Name of the owner administrator}
                            {--trial-days=14 : Trial length in days (0 = active plan, no trial)}
                            {--seats=5 : Seat limit (0 = unlimited)}
                            {--locale=pt-BR : Locale of the admin and the starter templates (en, pt-BR)}
                            {--no-welcome-email : Skip the welcome e-mail}
                            {--json : Emit the result as JSON (includes the one-time password)}';

    protected $description = 'Provision a new customer: tenant, workspace + vault, owner admin, trial plan, starter templates, welcome e-mail.';

    public function handle(TenantProvisioner $provisioner): int
    {
        foreach (['tenant-name', 'tenant-slug', 'workspace-name', 'workspace-slug', 'admin-email', 'admin-name'] as $required) {
            if (trim((string) $this->option($required)) === '') {
                $this->error("--{$required} is required.");

                return self::FAILURE;
            }
        }

        $seats = (int) $this->option('seats');
        $request = new ProvisioningRequest(
            tenantName: trim((string) $this->option('tenant-name')),
            tenantSlug: (string) $this->option('tenant-slug'),
            workspaceName: trim((string) $this->option('workspace-name')),
            workspaceSlug: (string) $this->option('workspace-slug'),
            adminEmail: (string) $this->option('admin-email'),
            adminName: trim((string) $this->option('admin-name')),
            trialDays: (int) $this->option('trial-days'),
            seats: $seats > 0 ? $seats : null,
            locale: (string) $this->option('locale'),
            sendWelcomeEmail: ! $this->option('no-welcome-email'),
        );

        try {
            $result = $provisioner->provision($request);
        } catch (TenantAlreadyExists $exception) {
            $this->error($exception->getMessage());

            return self::EXIT_ALREADY_EXISTS;
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($this->report($result), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info(sprintf('Tenant %s (%s) provisioned.', $result->tenant->slug, $result->tenant->name));
        $this->line(sprintf('  %-16s %s (id %d) — vault %s', 'workspace', $result->workspace->slug, $result->workspace->id, $result->workspace->vault_path));
        $this->line(sprintf('  %-16s %s / %s', 'plan', $result->tenant->plan_status, $result->tenant->trial_ends_at?->toDateString() ?? 'no trial deadline'));
        $this->line(sprintf('  %-16s %s', 'seats', $result->tenant->plan_seats ?? 'unlimited'));
        $this->line(sprintf('  %-16s %d installed', 'templates', count($result->templatesInstalled)));
        foreach ($result->urls() as $key => $url) {
            $this->line(sprintf('  %-16s %s', $key, $url));
        }
        $this->newLine();
        $this->line(sprintf('  %-16s %s (%s)', 'admin', $result->admin->email, $result->adminCreated ? 'created' : 'existing user, password unchanged'));
        if ($result->password !== null) {
            $this->line(sprintf('  %-16s %s', 'password', $result->password));
            $this->comment('  Shown once. It is not stored in clear text, logged, or audited. Hand it over securely.');
        }
        if ($result->welcomeEmailSent) {
            $this->line(sprintf('  %-16s sent to %s', 'welcome e-mail', $result->admin->email));
        } elseif ($result->welcomeEmailError !== null) {
            $this->warn('  welcome e-mail failed: '.$result->welcomeEmailError);
        } else {
            $this->line(sprintf('  %-16s skipped', 'welcome e-mail'));
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function report(ProvisioningResult $result): array
    {
        return [
            'tenant' => ['id' => $result->tenant->id, 'slug' => $result->tenant->slug, 'name' => $result->tenant->name],
            'plan' => [
                'status' => $result->tenant->plan_status,
                'trial_ends_at' => $result->tenant->trial_ends_at?->toIso8601String(),
                'seats' => $result->tenant->plan_seats,
            ],
            'workspace' => ['id' => $result->workspace->id, 'slug' => $result->workspace->slug, 'name' => $result->workspace->name, 'vault_path' => $result->workspace->vault_path],
            'admin' => ['id' => $result->admin->id, 'email' => $result->admin->email, 'name' => $result->admin->name, 'created' => $result->adminCreated, 'password' => $result->password],
            'templates_installed' => $result->templatesInstalled,
            'urls' => $result->urls(),
            'welcome_email' => ['sent' => $result->welcomeEmailSent, 'error' => $result->welcomeEmailError],
        ];
    }
}
