<?php

namespace App\Console\Commands;

use App\Domain\Auth\MachineTokenIssuer;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Issues an MCP machine token from the shell (hosted operators over SSH). The
 * token is printed once and never stored in clear text.
 */
final class McpTokenCommand extends Command
{
    protected $signature = 'mcp:token {email : User the token acts as}
                            {--tenant= : Tenant slug (defaults to the only tenant the user belongs to)}
                            {--name=MCP client : Label shown in the admin token list}
                            {--json : Emit JSON}';

    protected $description = 'Issue a machine token for MCP clients (Claude Code, Cursor, Claude Desktop).';

    public function handle(MachineTokenIssuer $issuer): int
    {
        $user = User::query()->where('email', mb_strtolower(trim((string) $this->argument('email'))))->first();
        if ($user === null) {
            $this->error(sprintf('User [%s] was not found.', $this->argument('email')));

            return self::FAILURE;
        }

        $tenant = $this->resolveTenant($user);
        if ($tenant === null) {
            return self::FAILURE;
        }

        $issued = $issuer->issue($user, $tenant, (string) $this->option('name'), 'cli:mcp:token');
        $url = rtrim((string) config('app.url'), '/').'/api/mcp';

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'id' => $issued['token']->id,
                'name' => $issued['token']->name,
                'user' => $user->email,
                'tenant' => $tenant->slug,
                'token' => $issued['plain'],
                'mcp_url' => $url,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info(sprintf('Machine token #%d "%s" issued for %s (tenant %s).', $issued['token']->id, $issued['token']->name, $user->email, $tenant->slug));
        $this->line('  token    '.$issued['plain']);
        $this->line('  mcp url  '.$url);
        $this->comment('  Shown once; only its hash is stored. Configure the client with header "Authorization: Bearer <token>".');
        $this->line('  guide    docs/mcp-clients.md');

        return self::SUCCESS;
    }

    private function resolveTenant(User $user): ?Tenant
    {
        $slug = (string) $this->option('tenant');
        if ($slug !== '') {
            $tenant = Tenant::query()->where('slug', $slug)->first();
            if ($tenant === null) {
                $this->error(sprintf('Tenant [%s] was not found.', $slug));
            }

            return $tenant;
        }

        $tenantIds = Membership::query()->where('subject_id', (string) $user->id)->distinct()->pluck('tenant_id');
        if ($tenantIds->count() === 1) {
            return Tenant::query()->find($tenantIds->first());
        }
        if ($tenantIds->isEmpty() && Tenant::query()->count() === 1) {
            return Tenant::query()->first();
        }

        $this->error('Pass --tenant=<slug>: the user belongs to '.$tenantIds->count().' tenant(s).');

        return null;
    }
}
