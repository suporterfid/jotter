<?php

namespace App\Domain\Provisioning;

use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Plan\PlanStatus;
use App\Domain\Vault\VaultRootGuard;
use App\Mail\WelcomeEmail;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * One-shot installation of a customer: tenant, workspace + vault, owner admin,
 * trial plan, starter templates, welcome e-mail. The generated password is
 * returned to the caller exactly once and never logged or audited.
 */
final class TenantProvisioner
{
    public const PASSWORD_LENGTH = 20;

    public function __construct(
        private readonly VaultRootGuard $rootGuard,
        private readonly TemplatePack $templatePack,
        private readonly AuditRecorder $auditRecorder = new AuditRecorder,
    ) {}

    /**
     * @throws TenantAlreadyExists when the tenant slug is taken
     * @throws \InvalidArgumentException for invalid input
     */
    public function provision(ProvisioningRequest $request): ProvisioningResult
    {
        $request->validate();

        if (Tenant::query()->where('slug', $request->tenantSlug)->exists()) {
            throw new TenantAlreadyExists($request->tenantSlug);
        }

        $vaultPath = $this->rootGuard->validate(
            rtrim((string) config('vault.base_path'), '/').'/'.$request->workspaceSlug,
        );
        $createdVault = false;
        if (! is_dir($vaultPath)) {
            if (! @mkdir($vaultPath, 0755, true) && ! is_dir($vaultPath)) {
                throw new \RuntimeException("Unable to create the vault directory [{$vaultPath}].");
            }
            $createdVault = true;
        }

        $password = null;

        try {
            /** @var ProvisioningResult $result */
            $result = DB::transaction(function () use ($request, $vaultPath, &$password): ProvisioningResult {
                $tenant = Tenant::create([
                    'slug' => $request->tenantSlug,
                    'name' => $request->tenantName,
                    'plan_status' => $request->trialDays > 0 ? PlanStatus::TRIAL->value : PlanStatus::ACTIVE->value,
                    'trial_ends_at' => $request->trialDays > 0 ? CarbonImmutable::now()->addDays($request->trialDays) : null,
                    'plan_seats' => $request->seats,
                    'plan_name' => $request->trialDays > 0 ? 'trial' : null,
                ]);

                $workspace = Workspace::create([
                    'tenant_id' => $tenant->id,
                    'slug' => $request->workspaceSlug,
                    'name' => $request->workspaceName,
                    'vault_path' => $vaultPath,
                ]);

                $existing = User::query()->where('email', $request->adminEmail)->first();
                if ($existing === null) {
                    // Reuse the platform bootstrap command for the admin user itself.
                    $password = Str::password(self::PASSWORD_LENGTH);
                    $exit = Artisan::call('platform:bootstrap-admin', ['email' => $request->adminEmail, 'password' => $password]);
                    if ($exit !== 0) {
                        throw new \RuntimeException('platform:bootstrap-admin failed: '.trim(Artisan::output()));
                    }
                }
                $admin = User::query()->where('email', $request->adminEmail)->firstOrFail();
                $admin->forceFill(array_filter([
                    'name' => $existing === null ? $request->adminName : null,
                    'locale' => $request->locale,
                    'is_active' => true,
                ], static fn ($value): bool => $value !== null))->save();

                Membership::create([
                    'tenant_id' => $tenant->id,
                    'workspace_id' => $workspace->id,
                    'subject_id' => (string) $admin->id,
                    'role' => 'owner',
                ]);

                $templates = $this->templatePack->install($workspace, $request->locale, false, (string) $admin->id);

                $this->auditRecorder->record(
                    event: AuditEvent::TENANT_PROVISIONED,
                    tenantId: $tenant->id,
                    workspaceId: $workspace->id,
                    actorId: 'cli:tenant:provision',
                    metadata: [
                        'tenant_slug' => $tenant->slug,
                        'workspace_slug' => $workspace->slug,
                        'admin_user_id' => $admin->id,
                        'admin_email' => $admin->email,
                        'admin_created' => $existing === null,
                        'trial_days' => $request->trialDays,
                        'seats' => $request->seats,
                        'locale' => $request->locale,
                        'templates_installed' => count($templates),
                    ],
                );

                return new ProvisioningResult(
                    tenant: $tenant,
                    workspace: $workspace,
                    admin: $admin,
                    adminCreated: $existing === null,
                    templatesInstalled: $templates,
                    password: $password,
                );
            });
        } catch (\Throwable $exception) {
            if ($createdVault) {
                $this->removeEmptyVault($vaultPath);
            }
            throw $exception;
        }

        $result->welcomeEmailSent = $this->sendWelcome($result, $request);

        return $result;
    }

    private function sendWelcome(ProvisioningResult $result, ProvisioningRequest $request): bool
    {
        if (! $request->sendWelcomeEmail) {
            return false;
        }

        if (trim((string) config('mail.from.address')) === '') {
            $result->welcomeEmailError = 'MAIL_FROM_ADDRESS is not set; configure the sender and re-send manually.';

            return false;
        }

        try {
            Mail::to($result->admin->email)->send(new WelcomeEmail(
                $result->admin,
                $result->workspace,
                $request->trialDays > 0 ? $request->trialDays : null,
            ));

            return true;
        } catch (\Throwable $exception) {
            // Provisioning already succeeded; the caller reports the failure.
            $result->welcomeEmailError = $exception->getMessage();

            return false;
        }
    }

    private function removeEmptyVault(string $vaultPath): void
    {
        $entries = @scandir($vaultPath) ?: [];
        if (count(array_diff($entries, ['.', '..'])) === 0) {
            @rmdir($vaultPath);
        }
    }
}
