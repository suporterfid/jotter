<?php

namespace App\Domain\Provisioning;

use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class ProvisioningRequest
{
    public readonly string $tenantSlug;

    public readonly string $workspaceSlug;

    public readonly string $adminEmail;

    public readonly string $locale;

    public function __construct(
        public readonly string $tenantName,
        string $tenantSlug,
        public readonly string $workspaceName,
        string $workspaceSlug,
        string $adminEmail,
        public readonly string $adminName,
        public readonly int $trialDays = 14,
        public readonly ?int $seats = 5,
        string $locale = 'pt-BR',
        public readonly bool $sendWelcomeEmail = true,
    ) {
        $this->tenantSlug = Str::slug($tenantSlug);
        $this->workspaceSlug = Str::slug($workspaceSlug);
        $this->adminEmail = Str::lower(trim($adminEmail));
        $this->locale = TemplatePack::normalizeLocale($locale);
    }

    /**
     * @throws \InvalidArgumentException
     */
    public function validate(): void
    {
        $validator = Validator::make([
            'tenant_name' => $this->tenantName,
            'tenant_slug' => $this->tenantSlug,
            'workspace_name' => $this->workspaceName,
            'workspace_slug' => $this->workspaceSlug,
            'admin_email' => $this->adminEmail,
            'admin_name' => $this->adminName,
            'trial_days' => $this->trialDays,
            'seats' => $this->seats,
        ], [
            'tenant_name' => ['required', 'string', 'max:191'],
            'tenant_slug' => ['required', 'string', 'max:191', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'workspace_name' => ['required', 'string', 'max:191'],
            'workspace_slug' => ['required', 'string', 'max:191', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'not_in:_templates'],
            'admin_email' => ['required', 'email:rfc', 'max:255'],
            'admin_name' => ['required', 'string', 'max:191'],
            'trial_days' => ['integer', 'min:0', 'max:365'],
            'seats' => ['nullable', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            throw new \InvalidArgumentException(implode(' ', $validator->errors()->all()));
        }
    }
}
