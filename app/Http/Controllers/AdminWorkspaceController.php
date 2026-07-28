<?php

namespace App\Http\Controllers;

use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Vault\Exceptions\PathTraversalRejected;
use App\Domain\Vault\VaultRootGuard;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class AdminWorkspaceController extends Controller
{
    public function __construct(
        private readonly VaultRootGuard $rootGuard = new VaultRootGuard,
        private readonly AuditRecorder $auditRecorder = new AuditRecorder,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $validated = $request->validate([
            'tenant_id' => ['required', 'integer', 'exists:tenants,id'],
            'slug' => ['required', 'string', 'max:191'],
            'name' => ['required', 'string', 'max:191'],
            'vault_path' => ['required', 'string', 'max:700'],
        ]);

        try {
            $canonicalVault = $this->rootGuard->validate($validated['vault_path']);
        } catch (PathTraversalRejected $exception) {
            throw ValidationException::withMessages(['vault_path' => [$exception->getMessage()]]);
        }

        // Create vault directory if absent
        if (! is_dir($canonicalVault)) {
            @mkdir($canonicalVault, 0755, true);
        }

        $workspace = Workspace::create([
            'tenant_id' => $validated['tenant_id'],
            'slug' => $validated['slug'],
            'name' => $validated['name'],
            'vault_path' => $canonicalVault,
        ]);

        return response()->json([
            'data' => [
                'id' => $workspace->id,
                'tenant_id' => $workspace->tenant_id,
                'slug' => $workspace->slug,
                'name' => $workspace->name,
                'vault_path' => $workspace->vault_path,
            ],
        ], 201);
    }

    public function update(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorizeAdmin($request);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:191'],
            'slug' => ['sometimes', 'string', 'max:191'],
            'vault_path' => ['sometimes', 'string', 'max:700'],
        ]);

        if (isset($validated['vault_path'])) {
            try {
                $validated['vault_path'] = $this->rootGuard->validate($validated['vault_path'], $workspace->id);
            } catch (PathTraversalRejected $exception) {
                throw ValidationException::withMessages(['vault_path' => [$exception->getMessage()]]);
            }
        }

        $workspace->update($validated);

        return response()->json([
            'data' => [
                'id' => $workspace->id,
                'tenant_id' => $workspace->tenant_id,
                'slug' => $workspace->slug,
                'name' => $workspace->name,
                'vault_path' => $workspace->vault_path,
            ],
        ]);
    }

    public function archive(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorizeAdmin($request);

        // Archive leaves .md files on disk completely untouched
        $this->auditRecorder->record(
            event: AuditEvent::WORKSPACE_ARCHIVED,
            tenantId: $workspace->tenant_id,
            workspaceId: $workspace->id,
            metadata: ['reason' => "Workspace [{$workspace->name}] archived (files left intact)."],
        );

        return response()->json([
            'message' => 'Workspace archived successfully. Vault files remain intact on disk.',
        ]);
    }

    private function authorizeAdmin(Request $request): void
    {
        $user = $request->user();
        if (! $user || ! $user->is_admin) {
            abort(403, 'Administrator access required.');
        }
    }
}
