<?php

namespace App\Http\Controllers;

use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Vault\Exceptions\PathTraversalRejected;
use App\Domain\Vault\Store\EndpointGuard;
use App\Domain\Vault\Store\TenantStorageAccess;
use App\Domain\Vault\Store\UnsafeEndpoint;
use App\Domain\Vault\Store\VaultStoreDrivers;
use App\Domain\Vault\Store\VaultStoreProbe;
use App\Domain\Vault\VaultRootGuard;
use App\Models\Tenant;
use App\Models\TenantVaultStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Tenant storage locations for workspace vaults (#436). Only an instance
 * administrator or a tenant-wide owner/admin may use it, and only when the
 * operator turned on a remote driver (`JOTTER_VAULT_DRIVERS`); otherwise every
 * route but `config` answers 404. Credentials are write-only: no response,
 * audit row or log line ever carries them.
 */
final class AdminVaultStoreController extends Controller
{
    public function __construct(
        private readonly VaultStoreDrivers $drivers = new VaultStoreDrivers,
        private readonly AuditRecorder $audit = new AuditRecorder,
        private readonly EndpointGuard $endpoints = new EndpointGuard,
        private readonly VaultRootGuard $rootGuard = new VaultRootGuard,
        private readonly TenantStorageAccess $access = new TenantStorageAccess,
    ) {}

    /** What the SPA needs to decide whether the Storage screen exists. */
    public function config(): JsonResponse
    {
        return response()->json(['data' => [
            'enabled' => $this->drivers->remoteEnabled(),
            'drivers' => $this->drivers->remoteEnabled() ? $this->drivers->enabled() : [],
            'implemented' => VaultStoreDrivers::IMPLEMENTED,
        ]]);
    }

    public function index(Request $request, Tenant $tenant): JsonResponse
    {
        $this->authorizeTenant($request, $tenant);

        $stores = TenantVaultStore::query()->where('tenant_id', $tenant->id)->orderBy('name')->get();

        return response()->json(['data' => $stores->map(fn (TenantVaultStore $store): array => $store->toApiArray())->values()]);
    }

    public function store(Request $request, Tenant $tenant): JsonResponse
    {
        $this->authorizeTenant($request, $tenant);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:191', Rule::unique('vault_stores', 'name')->where('tenant_id', $tenant->id)],
            'driver' => ['required', 'string', Rule::in($this->drivers->enabled())],
            'settings' => ['required', 'array'],
            'credentials' => ['sometimes', 'array'],
        ]);
        $validated = array_merge($validated, $request->validate($this->drivers->rules($validated['driver'], creating: true)));
        $settings = $this->checkedSettings($validated['driver'], $this->onlyRuleKeys('settings', $validated['driver'], $validated['settings']));
        $credentials = $this->onlyRuleKeys('credentials', $validated['driver'], $validated['credentials'] ?? []);

        $record = TenantVaultStore::create([
            'tenant_id' => $tenant->id,
            'name' => $validated['name'],
            'driver' => $validated['driver'],
            'settings' => $settings,
            'credentials' => $credentials === [] ? null : $credentials,
            'status' => TenantVaultStore::STATUS_UNTESTED,
        ]);

        $this->audit->record(AuditEvent::VAULT_STORE_CREATED, $tenant->id, null, $this->actorId($request), [
            'vault_store_id' => $record->id,
            'name' => $record->name,
            'driver' => $record->driver,
        ]);

        return response()->json(['data' => $record->toApiArray()], 201);
    }

    public function update(Request $request, TenantVaultStore $vaultStore): JsonResponse
    {
        $this->authorizeTenant($request, $vaultStore->tenant);

        $validated = $request->validate(array_merge([
            'name' => ['sometimes', 'string', 'max:191', Rule::unique('vault_stores', 'name')->where('tenant_id', $vaultStore->tenant_id)->ignore($vaultStore->id)],
            'settings' => ['sometimes', 'array'],
            'credentials' => ['sometimes', 'array'],
        ], $this->drivers->rules($vaultStore->driver, creating: false)));

        $changed = [];
        if (isset($validated['name'])) {
            $vaultStore->name = $validated['name'];
            $changed[] = 'name';
        }
        if (isset($validated['settings'])) {
            if ($vaultStore->workspaces()->exists()) {
                throw ValidationException::withMessages(['settings' => [__('messages.vault_store_settings_locked')]]);
            }
            $merged = array_merge($vaultStore->settings ?? [], $this->onlyRuleKeys('settings', $vaultStore->driver, $validated['settings']));
            $vaultStore->settings = $this->checkedSettings($vaultStore->driver, $merged, $vaultStore->id);
            $changed[] = 'settings';
        }
        if (isset($validated['credentials'])) {
            // Replacing credentials never needs the old ones: they cannot be read back.
            $credentials = $this->onlyRuleKeys('credentials', $vaultStore->driver, $validated['credentials']);
            $vaultStore->credentials = $credentials === [] ? null : $credentials;
            $changed[] = 'credentials';
        }

        if ($changed !== []) {
            $vaultStore->status = TenantVaultStore::STATUS_UNTESTED;
            $vaultStore->save();
            $this->audit->record(AuditEvent::VAULT_STORE_UPDATED, $vaultStore->tenant_id, null, $this->actorId($request), [
                'vault_store_id' => $vaultStore->id,
                'changed' => $changed,
            ]);
        }

        return response()->json(['data' => $vaultStore->fresh()->toApiArray()]);
    }

    public function destroy(Request $request, TenantVaultStore $vaultStore): JsonResponse
    {
        $this->authorizeTenant($request, $vaultStore->tenant);

        $neededByMove = \App\Models\VaultMove::query()
            ->whereIn('status', [\App\Models\VaultMove::COPYING, \App\Models\VaultMove::COMPLETED])
            ->where(fn ($query) => $query->where('from_store_id', $vaultStore->id)->orWhere('to_store_id', $vaultStore->id))
            ->exists();
        if ($vaultStore->workspaces()->exists() || $neededByMove) {
            return response()->json(['message' => __('messages.vault_store_in_use')], 409);
        }

        $id = $vaultStore->id;
        $tenantId = $vaultStore->tenant_id;
        $vaultStore->delete();

        $this->audit->record(AuditEvent::VAULT_STORE_DELETED, $tenantId, null, $this->actorId($request), ['vault_store_id' => $id]);

        return response()->json(['message' => __('messages.vault_store_deleted')]);
    }

    public function test(Request $request, TenantVaultStore $vaultStore, VaultStoreProbe $probe): JsonResponse
    {
        $this->authorizeTenant($request, $vaultStore->tenant);

        $result = $probe->probe($vaultStore);

        $this->audit->record(AuditEvent::VAULT_STORE_TESTED, $vaultStore->tenant_id, null, $this->actorId($request), [
            'vault_store_id' => $vaultStore->id,
            'ok' => $result['ok'],
        ]);

        return response()->json(['data' => $vaultStore->fresh()->toApiArray()], $result['ok'] ? 200 : 422);
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function checkedSettings(string $driver, array $settings, ?int $ignoreStoreId = null): array
    {
        if ($driver === VaultStoreDrivers::S3) {
            try {
                $this->endpoints->check((string) $settings['endpoint']);
            } catch (UnsafeEndpoint $exception) {
                throw ValidationException::withMessages(['settings.endpoint' => [$exception->getMessage()]]);
            }
        }

        if ($driver === VaultStoreDrivers::LOCAL) {
            try {
                $settings['root'] = $this->rootGuard->validate((string) $settings['root']);
            } catch (PathTraversalRejected $exception) {
                throw ValidationException::withMessages(['settings.root' => [$exception->getMessage()]]);
            }

            $taken = TenantVaultStore::query()->where('driver', VaultStoreDrivers::LOCAL)
                ->when($ignoreStoreId, fn ($query) => $query->whereKeyNot($ignoreStoreId))
                ->get()
                ->contains(fn (TenantVaultStore $other): bool => $this->overlaps((string) ($other->settings['root'] ?? ''), $settings['root']));
            if ($taken) {
                throw ValidationException::withMessages(['settings.root' => [__('messages.vault_store_root_taken')]]);
            }
        }

        if ($driver === VaultStoreDrivers::GITHUB) {
            $settings['branch'] = (string) ($settings['branch'] ?? 'main');
        }

        return $settings;
    }

    private function overlaps(string $a, string $b): bool
    {
        $a = rtrim($a, '/');
        $b = rtrim($b, '/');

        return $a !== '' && $b !== '' && ($a === $b || str_starts_with($a, $b.'/') || str_starts_with($b, $a.'/'));
    }

    /**
     * Keep only the keys the driver's rules know, so nothing unexpected is stored.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function onlyRuleKeys(string $group, string $driver, array $values): array
    {
        $keys = [];
        foreach (array_keys($this->drivers->rules($driver, creating: true)) as $rule) {
            if (str_starts_with($rule, $group.'.')) {
                $keys[] = substr($rule, strlen($group) + 1);
            }
        }

        return array_intersect_key($values, array_flip($keys));
    }

    private function authorizeTenant(Request $request, ?Tenant $tenant): void
    {
        $this->access->authorize($request, $tenant);
    }

    private function actorId(Request $request): ?string
    {
        return $this->access->subject($request)?->subjectId;
    }
}
