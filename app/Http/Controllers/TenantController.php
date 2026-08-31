<?php

namespace App\Http\Controllers;

use App\Domain\Auth\Contracts\IdentityProvider;
use App\Domain\Plan\TenantPlan;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantController extends Controller
{
    public function __construct(
        private readonly IdentityProvider $identityProvider,
        private readonly TenantPlan $tenantPlan,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $subject = $request->attributes->get('authenticated_subject');

        $query = Tenant::query()
            ->select(['id', 'slug', 'name', 'plan_status', 'trial_ends_at', 'plan_name', 'plan_seats'])
            ->orderBy('id');

        $accessibleIds = $this->identityProvider->accessibleTenantIds($subject);

        if ($accessibleIds !== null) {
            $query->whereIn('id', $accessibleIds);
        }

        $tenants = $query->get()->map(fn (Tenant $tenant): array => [
            'id' => $tenant->id,
            'slug' => $tenant->slug,
            'name' => $tenant->name,
            'plan' => $this->tenantPlan->payload($tenant),
        ])->values();

        return response()->json(['data' => $tenants]);
    }
}
