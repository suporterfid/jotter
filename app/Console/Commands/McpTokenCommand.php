<?php

namespace App\Console\Commands;

use App\Domain\Auth\InvalidMachineTokenOptions;
use App\Domain\Auth\MachineTokenIssuer;
use App\Domain\Auth\MachineTokenOptions;
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
                            {--workspace=* : Workspace slug or id the token may reach (repeatable; required for administrators)}
                            {--scope=* : mcp:read (default), mcp:write, api:read, api:write (repeatable)}
                            {--write-path=* : Write path as "glob:op,op", e.g. "inbox/{client}/**:create,append,update" (repeatable)}
                            {--client= : Client slug used for {client} in write paths (e.g. hermes, claude-code)}
                            {--expires= : Expiry such as 30d, 180d, 1y, or never (default)}
                            {--json : Emit JSON}';

    protected $description = 'Issue a scoped machine token for MCP clients (Claude Code, Codex, Hermes, Cursor, Claude Desktop).';

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

        $scopes = (array) $this->option('scope');
        $workspaces = (array) $this->option('workspace');

        try {
            $options = MachineTokenOptions::fromInput(
                owner: $user,
                tenant: $tenant,
                scopes: $scopes === [] ? ['mcp:read'] : $scopes,
                workspaces: $workspaces === [] ? null : $workspaces,
                writePaths: (array) $this->option('write-path'),
                clientSlug: $this->option('client') !== null ? (string) $this->option('client') : null,
                expires: $this->option('expires') !== null ? (string) $this->option('expires') : null,
            );
        } catch (InvalidMachineTokenOptions $exception) {
            foreach ($exception->errors() as $field => $message) {
                $this->error("--{$field}: {$message}");
            }

            return self::FAILURE;
        }

        $issued = $issuer->issue($user, $tenant, (string) $this->option('name'), $options, 'cli:mcp:token');
        $url = rtrim((string) config('app.url'), '/').'/api/mcp';
        $workspaceSlugs = $options->workspaceIds === null
            ? null
            : \App\Models\Workspace::query()->whereIn('id', $options->workspaceIds)->orderBy('id')->pluck('slug')->all();

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'id' => $issued['token']->id,
                'name' => $issued['token']->name,
                'user' => $user->email,
                'tenant' => $tenant->slug,
                'token' => $issued['plain'],
                'mcp_url' => $url,
                'scopes' => $options->scopes,
                'workspaces' => $workspaceSlugs,
                'write_paths' => $options->writePaths,
                'client' => $options->clientSlug,
                'expires_at' => $options->expiresAt?->toIso8601String(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info(sprintf('Machine token #%d "%s" issued for %s (tenant %s).', $issued['token']->id, $issued['token']->name, $user->email, $tenant->slug));
        $this->line('  token    '.$issued['plain']);
        $this->line('  mcp url  '.$url);
        $this->line('  scopes   '.implode(' ', $options->scopes));
        $this->line('  reach    '.($workspaceSlugs === null ? 'the user\'s workspace memberships' : implode(', ', $workspaceSlugs)));
        foreach ($options->writePaths as $path) {
            $this->line('  write    '.$path['glob'].' ('.implode(', ', $path['ops']).')');
        }
        if ($options->clientSlug !== null) {
            $this->line('  client   '.$options->clientSlug);
        }
        $this->line('  expires  '.($options->expiresAt?->toIso8601String() ?? 'never'));
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
