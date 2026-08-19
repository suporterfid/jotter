<?php

namespace App\Http\Controllers;

use App\Domain\Analytics\WorkspaceAnalyticsQuery;
use App\Domain\Auth\AuthenticatedSubject;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WorkspaceAnalyticsController extends Controller
{
    public function __construct(
        private readonly WorkspaceAnalyticsQuery $analytics,
    ) {}

    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        $subject = $request->attributes->get('authenticated_subject');
        if (! $subject instanceof AuthenticatedSubject) {
            return response()->json(['message' => __('messages.unauthenticated')], 401);
        }

        $validated = $request->validate([
            'days' => ['sometimes', 'integer', 'min:1', 'max:90'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json($this->analytics->forWorkspace(
            $workspace,
            $subject,
            (int) ($validated['days'] ?? 30),
            (int) ($validated['limit'] ?? 10),
        ));
    }
}
