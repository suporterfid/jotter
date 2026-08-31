<?php

namespace App\Domain\Provisioning;

use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Export\WorkspaceJsonBackup;
use App\Domain\Plan\TenantPlan;
use App\Models\Membership;
use App\Models\Note;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use ZipArchive;

/**
 * Full, portable copy of one tenant (LGPD data portability): every workspace
 * vault file (notes, attachments, templates), the JSON backup the API also
 * produces, and a manifest of tenant, plan, workspaces, memberships, and users.
 */
final class TenantExporter
{
    public function __construct(
        private readonly WorkspaceJsonBackup $jsonBackup,
        private readonly TenantPlan $tenantPlan,
        private readonly AuditRecorder $auditRecorder = new AuditRecorder,
    ) {}

    /**
     * @return array{path: string, workspaces: int, files: int, bytes: int}
     */
    public function export(Tenant $tenant, string $zipPath): array
    {
        @mkdir(dirname($zipPath), 0755, true);
        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("Unable to create export ZIP at {$zipPath}.");
        }

        $files = 0;
        $manifestWorkspaces = [];

        foreach ($tenant->workspaces()->orderBy('id')->get() as $workspace) {
            $prefix = 'workspaces/'.$workspace->slug.'/';
            $files += $this->addVault($zip, $workspace, $prefix.'vault/');

            $notes = Note::withTrashed()->where('workspace_id', $workspace->id)->orderBy('path')->get();
            $zip->addFromString($prefix.'backup.json', (string) json_encode(
                $this->jsonBackup->build($workspace, $notes),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));
            $files++;

            $manifestWorkspaces[] = [
                'id' => $workspace->id,
                'slug' => $workspace->slug,
                'name' => $workspace->name,
                'notes' => $notes->count(),
            ];
        }

        $zip->addFromString('tenant.json', (string) json_encode($this->manifest($tenant, $manifestWorkspaces), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $files++;
        $zip->close();

        $this->auditRecorder->record(
            event: AuditEvent::TENANT_EXPORTED,
            tenantId: $tenant->id,
            actorId: 'cli:tenant:export',
            metadata: ['tenant_slug' => $tenant->slug, 'workspaces' => count($manifestWorkspaces), 'files' => $files],
        );

        return [
            'path' => $zipPath,
            'workspaces' => count($manifestWorkspaces),
            'files' => $files,
            'bytes' => (int) filesize($zipPath),
        ];
    }

    private function addVault(ZipArchive $zip, Workspace $workspace, string $prefix): int
    {
        $root = realpath($workspace->vault_path);
        if ($root === false || ! is_dir($root)) {
            return 0;
        }

        $count = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        /** @var SplFileInfo $entry */
        foreach ($iterator as $entry) {
            $relative = ltrim(str_replace('\\', '/', substr($entry->getPathname(), strlen($root))), '/');
            if ($entry->isDir()) {
                $zip->addEmptyDir($prefix.$relative);
            } elseif ($entry->isFile() && ! $entry->isLink()) {
                $zip->addFile($entry->getPathname(), $prefix.$relative);
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  list<array<string, mixed>>  $workspaces
     * @return array<string, mixed>
     */
    private function manifest(Tenant $tenant, array $workspaces): array
    {
        $memberships = Membership::query()->where('tenant_id', $tenant->id)->orderBy('id')->get();
        $userIds = $memberships->pluck('subject_id')->filter(static fn ($id): bool => ctype_digit((string) $id))->map(static fn ($id): int => (int) $id)->unique()->all();
        $users = User::query()->whereIn('id', $userIds)->orderBy('id')->get(['id', 'name', 'email', 'locale', 'is_admin', 'is_active', 'created_at']);

        return [
            'format' => 'jotter-tenant-export',
            'version' => '1.0',
            'exported_at' => now()->toIso8601String(),
            'tenant' => [
                'id' => $tenant->id,
                'slug' => $tenant->slug,
                'name' => $tenant->name,
                'created_at' => $tenant->created_at?->toIso8601String(),
                'plan' => $this->tenantPlan->payload($tenant),
            ],
            'workspaces' => $workspaces,
            'memberships' => $memberships->map(static fn (Membership $membership): array => [
                'workspace_id' => $membership->workspace_id,
                'subject_id' => $membership->subject_id,
                'role' => $membership->role,
            ])->all(),
            'users' => $users->map(static fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'locale' => $user->locale,
                'is_admin' => (bool) $user->is_admin,
                'is_active' => (bool) $user->is_active,
                'created_at' => $user->created_at?->toIso8601String(),
            ])->all(),
        ];
    }
}
