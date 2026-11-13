<?php

namespace App\Http\Controllers;

use App\Domain\Auth\Contracts\IdentityProvider;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkspaceController extends Controller
{
    public function __construct(
        private readonly IdentityProvider $identityProvider
    ) {}

    public function index(Request $request): JsonResponse
    {
        $subject = $request->attributes->get('authenticated_subject');

        $query = Workspace::query()->select(['id', 'tenant_id', 'slug', 'name'])->orderBy('id');

        $accessibleIds = $this->identityProvider->accessibleWorkspaceIds($subject);

        if ($accessibleIds !== null) {
            $query->whereIn('id', $accessibleIds);
        }

        if ($tenantId = $request->query('tenant_id')) {
            $query->where('tenant_id', (int) $tenantId);
        }

        $quota = app(\App\Domain\Quota\WorkspaceQuota::class);
        $query->addSelect(['vault_path', 'storage_quota_mb', 'storage_bytes', 'vault_store_id', 'storage_state']);

        return response()->json(['data' => $query->get()->map(static fn (Workspace $workspace): array => [
            'id' => $workspace->id,
            'tenant_id' => $workspace->tenant_id,
            'slug' => $workspace->slug,
            'name' => $workspace->name,
            // Storage quota (#390): drives the "almost full" warning in the SPA.
            'storage' => $quota->status($workspace) + ['quota_mb' => $workspace->storage_quota_mb],
            // Remote store sync badge (#441): pending / synced / delayed / read_only; null = local folder.
            'sync' => \App\Domain\Vault\Store\GitHub\GitHubLifecycle::status($workspace),
        ])->values()]);
    }
}
