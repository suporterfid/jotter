<?php

namespace App\Domain\Provisioning;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;

final class ProvisioningResult
{
    public bool $welcomeEmailSent = false;

    public ?string $welcomeEmailError = null;

    /**
     * @param  list<string>  $templatesInstalled
     * @param  ?string  $password  Set only when the admin user was created; shown once, never stored or logged.
     */
    public function __construct(
        public readonly Tenant $tenant,
        public readonly Workspace $workspace,
        public readonly User $admin,
        public readonly bool $adminCreated,
        public readonly array $templatesInstalled,
        public readonly ?string $password,
    ) {}

    /**
     * @return array<string, string>
     */
    public function urls(): array
    {
        $base = rtrim((string) config('app.url'), '/');

        return [
            'app' => $base.'/',
            'login' => $base.'/',
            'webdav' => $base.'/api/webdav/'.$this->workspace->id,
            'mcp' => $base.'/api/mcp',
            'health' => $base.'/api/health',
        ];
    }
}
