<?php

namespace App\Http\Controllers;

use App\Domain\Auth\AuthenticatedSubject;
use App\Domain\Auth\MachineTokenIssuer;
use App\Models\MachineToken;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Administrator management of MCP machine tokens. Plaintext is returned by
 * `store` exactly once; listings expose metadata only.
 */
final class AdminMachineTokenController extends Controller
{
    public function __construct(
        private readonly MachineTokenIssuer $issuer,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $tokens = MachineToken::query()->orderByDesc('id')->get();
        $users = User::query()->whereIn('id', $tokens->pluck('subject_id')->filter(static fn ($id): bool => ctype_digit((string) $id))->all())->get()->keyBy('id');
        $tenants = Tenant::query()->whereIn('id', $tokens->pluck('tenant_id')->all())->get()->keyBy('id');

        return response()->json(['data' => $tokens->map(fn (MachineToken $token): array => $this->present($token, $users->get((int) $token->subject_id), $tenants->get($token->tenant_id)))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $validated = $request->validate([
            'tenant_id' => ['required', 'integer', 'exists:tenants,id'],
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'name' => ['required', 'string', 'max:191'],
        ]);

        $tenant = Tenant::query()->findOrFail($validated['tenant_id']);
        $user = User::query()->findOrFail($validated['user_id']);

        $issued = $this->issuer->issue($user, $tenant, $validated['name'], $this->currentSubject($request)?->subjectId);

        return response()->json([
            'data' => $this->present($issued['token'], $user, $tenant) + [
                'token' => $issued['plain'],
                'mcp_url' => rtrim((string) config('app.url'), '/').'/api/mcp',
            ],
        ], 201);
    }

    public function destroy(Request $request, MachineToken $machineToken): JsonResponse
    {
        $this->authorizeAdmin($request);

        $this->issuer->revoke($machineToken, $this->currentSubject($request)?->subjectId);

        return response()->json(['message' => __('messages.machine_token_revoked')]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(MachineToken $token, ?User $user, ?Tenant $tenant): array
    {
        return [
            'id' => $token->id,
            'name' => $token->name,
            'tenant_id' => $token->tenant_id,
            'tenant_slug' => $tenant?->slug,
            'user_id' => (int) $token->subject_id,
            'user_email' => $user?->email,
            'created_at' => $token->created_at?->toIso8601String(),
            'revoked_at' => $token->revoked_at?->toIso8601String(),
        ];
    }

    private function authorizeAdmin(Request $request): void
    {
        if (config('jotter.auth_bypass', false)) {
            return;
        }

        $subject = $this->currentSubject($request);
        if (! $subject || ! $subject->isAdmin) {
            abort(403, 'Administrator access required.');
        }
    }

    private function currentSubject(Request $request): ?AuthenticatedSubject
    {
        $subject = $request->attributes->get('authenticated_subject');

        return $subject instanceof AuthenticatedSubject ? $subject : null;
    }
}
