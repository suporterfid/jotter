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

        return response()->json(['data' => $query->get()]);
    }
}
