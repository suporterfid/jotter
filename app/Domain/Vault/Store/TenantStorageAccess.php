<?php

namespace App\Domain\Vault\Store;

use App\Domain\Auth\AuthenticatedSubject;
use App\Models\Membership;
use App\Models\Tenant;
use Illuminate\Http\Request;

/**
 * Who may manage a tenant's vault stores and move its workspaces (#436/#437):
 * an instance administrator or a tenant-wide owner/admin, and only when the
 * operator turned on a remote driver. Otherwise the routes answer 404.
 */
final class TenantStorageAccess
{
    public function __construct(private readonly VaultStoreDrivers $drivers = new VaultStoreDrivers) {}

    public function authorize(Request $request, ?Tenant $tenant): void
    {
        if (! $this->drivers->remoteEnabled() || $tenant === null) {
            abort(404);
        }

        if (config('jotter.auth_bypass', false)) {
            return;
        }

        $subject = $this->subject($request);
        if ($subject === null) {
            abort(401);
        }
        if ($subject->isAdmin) {
            return;
        }

        $subjectIds = $subject->user?->membershipSubjectIds() ?? array_values(array_filter([$subject->subjectId, $subject->email]));
        $allowed = Membership::query()
            ->where('tenant_id', $tenant->id)
            ->whereNull('workspace_id')
            ->whereIn('subject_id', $subjectIds)
            ->whereIn('role', ['owner', 'admin'])
            ->exists();

        if (! $allowed) {
            abort(403, __('messages.tenant_admin_required'));
        }
    }

    public function subject(Request $request): ?AuthenticatedSubject
    {
        $subject = $request->attributes->get('authenticated_subject');

        return $subject instanceof AuthenticatedSubject ? $subject : null;
    }
}
