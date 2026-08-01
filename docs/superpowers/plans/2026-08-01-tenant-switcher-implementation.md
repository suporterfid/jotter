# Tenant Switcher Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a user with `Membership` in 2+ tenants see and switch which tenant is active, filtering the workspace list to that tenant — with zero UI or behavior change for the common single-tenant case.

**Architecture:** `IdentityProvider` gains `accessibleTenantIds()` (mirroring `accessibleWorkspaceIds()`), backed by a new `TenantController@index` (`GET /api/tenants`). `WorkspaceController@index` gains an optional `?tenant_id=` filter. A `TenantSwitcher.vue` mirrors `WorkspaceSwitcher.vue` structurally, mounted in `Sidebar.vue` only when 2+ tenants exist. `App.vue` fetches tenants alongside workspaces and threads an `activeTenantId` the same way it already threads `activeWorkspaceId`.

**Tech Stack:** Laravel 11 (PHP 8.2+/8.3), PHPUnit, Vue 3 `<script setup>` + TypeScript, Vitest + `@vue/test-utils`.

## Global Constraints

- Use only `./scripts/jt.sh` wrappers for all dependency/build/test/db commands.
- The new `Sidebar.vue` props (`tenants`, `activeTenantId`) must be **optional** with safe defaults (`tenants ?? []`), NOT required — this repo has twice shipped a required-prop-breaks-`vue-tsc` bug (`PanelHeader`'s `collapsed`, `Sidebar`'s `workspaces`) from adding a required prop and missing a mount site elsewhere; making these optional sidesteps that whole class of bug rather than hunting every `mount(Sidebar, ...)` call again.
- No new admin UI for tenant management — out of scope per the spec.
- No new UX for "switched to a tenant with zero workspaces" beyond what already happens today for an empty workspace list.
- `accessibleTenantIds()` returns tenant ids from a user's memberships regardless of whether the membership row is tenant-wide (`workspace_id` null) or workspace-specific — any `Membership` row for a tenant means that tenant is accessible.

---

### Task 1: `accessibleTenantIds()` + shared subject-id helper on the identity providers

**Files:**
- Modify: `app/Domain/Auth/Contracts/IdentityProvider.php`
- Modify: `app/Domain/Auth/Providers/LocalIdentityProvider.php`
- Modify: `app/Domain/Auth/Providers/GrandpaSSOnIdentityProvider.php`
- Test: `tests/Unit/LocalIdentityProviderTenantAccessTest.php` (new)

**Interfaces:**
- Produces: `IdentityProvider::accessibleTenantIds(AuthenticatedSubject $subject): ?array` — null means unrestricted (admin); `array<int>` is the exact set of tenant ids. Task 2 consumes this directly.

- [ ] **Step 1: Write the failing unit test**

Create `tests/Unit/LocalIdentityProviderTenantAccessTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Domain\Auth\AuthenticatedSubject;
use App\Domain\Auth\Providers\LocalIdentityProvider;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocalIdentityProviderTenantAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_gets_null_meaning_unrestricted(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $subject = new AuthenticatedSubject(
            subjectId: (string) $admin->id,
            email: $admin->email,
            name: $admin->name,
            isAdmin: true,
            user: $admin,
        );

        $provider = new LocalIdentityProvider();

        $this->assertNull($provider->accessibleTenantIds($subject));
    }

    public function test_non_admin_sees_tenant_from_direct_workspace_membership(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);
        $ws = Workspace::create(['tenant_id' => $tenant->id, 'slug' => 'a', 'name' => 'A', 'vault_path' => storage_path('app/vaults/tenant_access_a')]);

        Membership::create([
            'subject_id' => (string) $user->id,
            'tenant_id' => $tenant->id,
            'workspace_id' => $ws->id,
            'role' => 'editor',
        ]);

        $subject = new AuthenticatedSubject(
            subjectId: (string) $user->id,
            email: $user->email,
            name: $user->name,
            isAdmin: false,
            user: $user,
        );

        $provider = new LocalIdentityProvider();

        $this->assertEqualsCanonicalizing([$tenant->id], $provider->accessibleTenantIds($subject));
    }

    public function test_non_admin_sees_tenant_from_tenant_wide_membership(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);

        Membership::create([
            'subject_id' => (string) $user->id,
            'tenant_id' => $tenant->id,
            'workspace_id' => null,
            'role' => 'viewer',
        ]);

        $subject = new AuthenticatedSubject(
            subjectId: (string) $user->id,
            email: $user->email,
            name: $user->name,
            isAdmin: false,
            user: $user,
        );

        $provider = new LocalIdentityProvider();

        $this->assertEqualsCanonicalizing([$tenant->id], $provider->accessibleTenantIds($subject));
    }

    public function test_non_admin_sees_multiple_distinct_tenants(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $tenantA = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);
        $tenantB = Tenant::create(['slug' => 'globex', 'name' => 'Globex']);
        $wsA = Workspace::create(['tenant_id' => $tenantA->id, 'slug' => 'a', 'name' => 'A', 'vault_path' => storage_path('app/vaults/tenant_access_multi_a')]);

        Membership::create(['subject_id' => (string) $user->id, 'tenant_id' => $tenantA->id, 'workspace_id' => $wsA->id, 'role' => 'editor']);
        Membership::create(['subject_id' => (string) $user->id, 'tenant_id' => $tenantB->id, 'workspace_id' => null, 'role' => 'viewer']);

        $subject = new AuthenticatedSubject(
            subjectId: (string) $user->id,
            email: $user->email,
            name: $user->name,
            isAdmin: false,
            user: $user,
        );

        $provider = new LocalIdentityProvider();

        $this->assertEqualsCanonicalizing([$tenantA->id, $tenantB->id], $provider->accessibleTenantIds($subject));
    }

    public function test_user_with_no_membership_sees_no_tenants(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $subject = new AuthenticatedSubject(
            subjectId: (string) $user->id,
            email: $user->email,
            name: $user->name,
            isAdmin: false,
            user: $user,
        );

        $provider = new LocalIdentityProvider();

        $this->assertSame([], $provider->accessibleTenantIds($subject));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./scripts/jt.sh artisan test --filter=LocalIdentityProviderTenantAccessTest`
Expected: FAIL with "Call to undefined method LocalIdentityProvider::accessibleTenantIds()"

- [ ] **Step 3: Add the method to the contract**

In `app/Domain/Auth/Contracts/IdentityProvider.php`, add after `accessibleWorkspaceIds`:

```php
    /**
     * Return the tenant ids the subject has any membership in, or null if unrestricted (e.g. an admin).
     *
     * @return array<int>|null
     */
    public function accessibleTenantIds(AuthenticatedSubject $subject): ?array;
```

- [ ] **Step 4: Extract the shared subject-id resolution helper, then implement `accessibleTenantIds()`, in `LocalIdentityProvider`**

`isAuthorizedForWorkspace()` and `accessibleWorkspaceIds()` currently each inline this same block:

```php
        $subjectIds = array_filter([
            $subject->subjectId,
            (string) $subject->user?->id,
            $subject->email,
        ]);

        if ($subject->user) {
            $identitySubjectIds = $subject->user->identities()->pluck('subject_id')->all();
            $subjectIds = array_unique(array_merge($subjectIds, $identitySubjectIds));
        }
```

Extract it into a new private method, add near the top of the class (right after `resolveIdentity`, before `authenticate`, or anywhere convenient — placement doesn't affect behavior):

```php
    /**
     * @return array<string>
     */
    private function resolveSubjectIds(AuthenticatedSubject $subject): array
    {
        $subjectIds = array_filter([
            $subject->subjectId,
            (string) $subject->user?->id,
            $subject->email,
        ]);

        if ($subject->user) {
            $identitySubjectIds = $subject->user->identities()->pluck('subject_id')->all();
            $subjectIds = array_unique(array_merge($subjectIds, $identitySubjectIds));
        }

        return $subjectIds;
    }
```

Replace the inlined block in `isAuthorizedForWorkspace()` (lines 161-170) with:

```php
        $subjectIds = $this->resolveSubjectIds($subject);
```

Replace the inlined block in `accessibleWorkspaceIds()` (lines 188-197) with the same one-liner:

```php
        $subjectIds = $this->resolveSubjectIds($subject);
```

Add the new method, right after `accessibleWorkspaceIds()`:

```php
    public function accessibleTenantIds(AuthenticatedSubject $subject): ?array
    {
        if ($subject->isAdmin) {
            return null;
        }

        $subjectIds = $this->resolveSubjectIds($subject);

        return Membership::query()
            ->whereIn('subject_id', $subjectIds)
            ->distinct()
            ->pluck('tenant_id')
            ->all();
    }
```

- [ ] **Step 5: Implement in `GrandpaSSOnIdentityProvider`**

Add after `accessibleWorkspaceIds()`:

```php
    public function accessibleTenantIds(AuthenticatedSubject $subject): ?array
    {
        if ($subject->isAdmin) {
            return null;
        }

        return $this->localProvider->accessibleTenantIds($subject);
    }
```

- [ ] **Step 6: Run test to verify it passes**

Run: `./scripts/jt.sh artisan test --filter=LocalIdentityProviderTenantAccessTest`
Expected: PASS (5 tests)

- [ ] **Step 7: Run the full PHP suite to confirm the extraction didn't break `isAuthorizedForWorkspace`/`accessibleWorkspaceIds`**

Run: `./scripts/jt.sh artisan test`
Expected: all PHP tests pass, including `WorkspaceIndexTest` and the existing `AuthorizeWorkspaceAccess`-related tests.

- [ ] **Step 8: Commit**

```bash
git add app/Domain/Auth/Contracts/IdentityProvider.php app/Domain/Auth/Providers/LocalIdentityProvider.php app/Domain/Auth/Providers/GrandpaSSOnIdentityProvider.php tests/Unit/LocalIdentityProviderTenantAccessTest.php
git commit -m "feat: add accessibleTenantIds and extract shared subject-id resolution"
```

---

### Task 2: `TenantController@index` and `?tenant_id=` filter on `WorkspaceController@index`

**Files:**
- Create: `app/Http/Controllers/TenantController.php`
- Modify: `app/Http/Controllers/WorkspaceController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/TenantIndexTest.php` (new)
- Test: `tests/Feature/WorkspaceIndexTest.php` (add `tenant_id` param cases)

**Interfaces:**
- Consumes: `IdentityProvider::accessibleTenantIds()` from Task 1.
- Produces: `GET /api/tenants` → `{"data": [{id, slug, name}, ...]}`. `GET /api/workspaces?tenant_id=X` additionally filters by tenant. Task 3's `getTenants()`/`getWorkspaces(tenantId?)` consume these directly.

- [ ] **Step 1: Write the failing feature tests**

Create `tests/Feature/TenantIndexTest.php`, mirroring `WorkspaceIndexTest.php`'s three cases:

```php
<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admin_only_sees_tenants_they_have_membership_for(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $tenantA = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);
        $tenantB = Tenant::create(['slug' => 'globex', 'name' => 'Globex']);

        Membership::create([
            'subject_id' => (string) $user->id,
            'tenant_id' => $tenantA->id,
            'workspace_id' => null,
            'role' => 'viewer',
        ]);

        $res = $this->actingAs($user)->getJson('/api/tenants');

        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$tenantA->id], $ids);
    }

    public function test_admin_sees_all_tenants(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $tenantA = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);
        $tenantB = Tenant::create(['slug' => 'globex', 'name' => 'Globex']);

        $res = $this->actingAs($admin)->getJson('/api/tenants');

        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$tenantA->id, $tenantB->id], $ids);
    }

    public function test_user_with_no_membership_sees_empty_tenant_list(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        Tenant::create(['slug' => 'acme', 'name' => 'Acme']);

        $res = $this->actingAs($user)->getJson('/api/tenants');

        $res->assertOk();
        $this->assertSame([], $res->json('data'));
    }
}
```

Add to `tests/Feature/WorkspaceIndexTest.php`, after the existing three tests:

```php
    public function test_tenant_id_param_scopes_workspaces_to_that_tenant(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $tenantA = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);
        $tenantB = Tenant::create(['slug' => 'globex', 'name' => 'Globex']);
        $wsA = Workspace::create(['tenant_id' => $tenantA->id, 'slug' => 'a', 'name' => 'A', 'vault_path' => storage_path('app/vaults/idx_tenant_a')]);
        $wsB = Workspace::create(['tenant_id' => $tenantB->id, 'slug' => 'b', 'name' => 'B', 'vault_path' => storage_path('app/vaults/idx_tenant_b')]);

        Membership::create(['subject_id' => (string) $user->id, 'tenant_id' => $tenantA->id, 'workspace_id' => null, 'role' => 'viewer']);
        Membership::create(['subject_id' => (string) $user->id, 'tenant_id' => $tenantB->id, 'workspace_id' => null, 'role' => 'viewer']);

        $res = $this->actingAs($user)->getJson('/api/workspaces?tenant_id='.$tenantA->id);

        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$wsA->id], $ids);
    }

    public function test_tenant_id_param_for_inaccessible_tenant_yields_empty_list(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $tenantA = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);
        $tenantB = Tenant::create(['slug' => 'globex', 'name' => 'Globex']);
        Workspace::create(['tenant_id' => $tenantB->id, 'slug' => 'b', 'name' => 'B', 'vault_path' => storage_path('app/vaults/idx_tenant_inaccessible')]);

        Membership::create(['subject_id' => (string) $user->id, 'tenant_id' => $tenantA->id, 'workspace_id' => null, 'role' => 'viewer']);

        $res = $this->actingAs($user)->getJson('/api/workspaces?tenant_id='.$tenantB->id);

        $res->assertOk();
        $this->assertSame([], $res->json('data'));
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./scripts/jt.sh artisan test --filter=TenantIndexTest`
Expected: FAIL — route `/api/tenants` doesn't exist (404).

Run: `./scripts/jt.sh artisan test --filter=WorkspaceIndexTest`
Expected: the two new `tenant_id` tests FAIL (param is ignored today, so both tenants' workspaces come back for the first new test).

- [ ] **Step 3: Create `TenantController`**

Create `app/Http/Controllers/TenantController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Domain\Auth\Contracts\IdentityProvider;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantController extends Controller
{
    public function __construct(
        private readonly IdentityProvider $identityProvider
    ) {}

    public function index(Request $request): JsonResponse
    {
        $subject = $request->attributes->get('authenticated_subject');

        $query = Tenant::query()->select(['id', 'slug', 'name'])->orderBy('id');

        $accessibleIds = $this->identityProvider->accessibleTenantIds($subject);

        if ($accessibleIds !== null) {
            $query->whereIn('id', $accessibleIds);
        }

        return response()->json(['data' => $query->get()]);
    }
}
```

- [ ] **Step 4: Add the route**

In `routes/api.php`, add right after the existing `/workspaces` route (line 34):

```php
    Route::get('/tenants', [\App\Http\Controllers\TenantController::class, 'index']);
```

- [ ] **Step 5: Add the `tenant_id` filter to `WorkspaceController@index`**

Current:

```php
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
```

Change to:

```php
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

        return response()->json(['data' => $query->get()]);
    }
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `./scripts/jt.sh artisan test --filter=TenantIndexTest`
Expected: PASS (3 tests)

Run: `./scripts/jt.sh artisan test --filter=WorkspaceIndexTest`
Expected: PASS (5 tests — 3 pre-existing + 2 new)

- [ ] **Step 7: Run the full PHP suite**

Run: `./scripts/jt.sh artisan test`
Expected: all pass, no regressions.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/TenantController.php app/Http/Controllers/WorkspaceController.php routes/api.php tests/Feature/TenantIndexTest.php tests/Feature/WorkspaceIndexTest.php
git commit -m "feat: add GET /api/tenants and tenant_id filter on GET /api/workspaces"
```

---

### Task 3: Frontend `Tenant` type, `getTenants()`/`getWorkspaces(tenantId?)`, and `TenantSwitcher.vue`

**Files:**
- Modify: `frontend/src/services/types.ts`
- Modify: `frontend/src/services/api.ts`
- Create: `frontend/src/components/TenantSwitcher.vue`
- Test: `frontend/src/TenantSwitcher.spec.ts` (new)

**Interfaces:**
- Produces: `Tenant` type (`{id: number, slug: string, name: string}`); `getTenants(): Promise<Tenant[]>`; `getWorkspaces(tenantId?: number): Promise<Workspace[]>` (existing signature gains an optional param, backward compatible — every existing call site with no argument is unaffected). `TenantSwitcher.vue` props `{tenants: Tenant[], activeTenantId: number | null}`, emits `(e: 'switch', tenantId: number): void`. Task 4 (Sidebar.vue) consumes all of these.

- [ ] **Step 1: Write the failing component test**

Create `frontend/src/TenantSwitcher.spec.ts`:

```typescript
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import TenantSwitcher from './components/TenantSwitcher.vue'

const tenants = [
  { id: 1, slug: 'acme', name: 'Acme Corp' },
  { id: 2, slug: 'globex', name: 'Globex Inc' },
]

describe('TenantSwitcher', () => {
  it('renders one option per tenant', () => {
    const wrapper = mount(TenantSwitcher, { props: { tenants, activeTenantId: 1 } })
    const options = wrapper.findAll('option')
    expect(options).toHaveLength(2)
    expect(options[0].text()).toBe('Acme Corp')
    expect(options[1].text()).toBe('Globex Inc')
  })

  it('selects the active tenant by default', () => {
    const wrapper = mount(TenantSwitcher, { props: { tenants, activeTenantId: 2 } })
    expect((wrapper.find('select').element as HTMLSelectElement).value).toBe('2')
  })

  it('emits switch with the chosen tenant id', async () => {
    const wrapper = mount(TenantSwitcher, { props: { tenants, activeTenantId: 1 } })
    await wrapper.find('select').setValue('2')
    expect(wrapper.emitted('switch')![0]).toEqual([2])
  })

  it('renders correctly with a single tenant', () => {
    const wrapper = mount(TenantSwitcher, { props: { tenants: [tenants[0]], activeTenantId: 1 } })
    expect(wrapper.findAll('option')).toHaveLength(1)
    expect(wrapper.find('select').exists()).toBe(true)
  })
})
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./scripts/jt.sh npm -- run test -- TenantSwitcher.spec.ts`
Expected: FAIL — `Failed to resolve import "./components/TenantSwitcher.vue"`

- [ ] **Step 3: Add the `Tenant` type**

In `frontend/src/services/types.ts`, add near the top (right after the `Workspace` interface):

```typescript
export interface Tenant {
  id: number
  slug: string
  name: string
}
```

- [ ] **Step 4: Add `getTenants()` and update `getWorkspaces()` in `frontend/src/services/api.ts`**

Add `Tenant` to the type import at the top of the file (extend the existing `import type { Workspace, ... } from './types'` line with `Tenant`).

Current `getWorkspaces`:

```typescript
export async function getWorkspaces(): Promise<Workspace[]> {
  const response = await api.get<{ data: Workspace[] }>('/workspaces')
  return response.data.data
}
```

Change to:

```typescript
export async function getWorkspaces(tenantId?: number): Promise<Workspace[]> {
  const response = await api.get<{ data: Workspace[] }>('/workspaces', {
    params: tenantId !== undefined ? { tenant_id: tenantId } : undefined,
  })
  return response.data.data
}

export async function getTenants(): Promise<Tenant[]> {
  const response = await api.get<{ data: Tenant[] }>('/tenants')
  return response.data.data
}
```

(Placed right after `getWorkspaces` — keeps the two workspace/tenant listing functions adjacent.)

- [ ] **Step 5: Create `TenantSwitcher.vue`**

Create `frontend/src/components/TenantSwitcher.vue`, structurally identical to `WorkspaceSwitcher.vue`:

```vue
<template>
  <div class="tenant-switcher">
    <select
      class="tenant-switcher-select"
      data-testid="tenant-switcher-select"
      :value="activeTenantId ?? undefined"
      aria-label="Switch tenant"
      @change="handleChange"
    >
      <option v-for="tenant in tenants" :key="tenant.id" :value="tenant.id">{{ tenant.name }}</option>
    </select>
  </div>
</template>

<script setup lang="ts">
import type { Tenant } from '../services/types'

defineProps<{
  tenants: Tenant[]
  activeTenantId: number | null
}>()

const emit = defineEmits<{
  (e: 'switch', tenantId: number): void
}>()

function handleChange(event: Event) {
  const value = Number((event.target as HTMLSelectElement).value)
  emit('switch', value)
}
</script>

<style scoped>
.tenant-switcher {
  padding: 0 var(--space-2);
}

.tenant-switcher-select {
  width: 100%;
  background: var(--color-surface);
  color: var(--color-text);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  padding: var(--space-2) var(--space-3);
  font-size: 0.8125rem;
  font-weight: 500;
  cursor: pointer;
  transition: border-color var(--duration-standard) var(--ease-standard),
              background-color var(--duration-standard) var(--ease-standard);
}

.tenant-switcher-select:hover {
  background: var(--color-hover);
}
</style>
```

- [ ] **Step 6: Run test to verify it passes**

Run: `./scripts/jt.sh npm -- run test -- TenantSwitcher.spec.ts`
Expected: PASS (4 tests)

- [ ] **Step 7: Run the full frontend suite to check for regressions**

Run: `./scripts/jt.sh npm -- run test`
Expected: all pass — the `getWorkspaces()` signature change is backward compatible (optional param), so no existing call site or mock needs updating.

- [ ] **Step 8: Commit**

```bash
git add frontend/src/services/types.ts frontend/src/services/api.ts frontend/src/components/TenantSwitcher.vue frontend/src/TenantSwitcher.spec.ts
git commit -m "feat: add Tenant type, getTenants, and TenantSwitcher component"
```

---

### Task 4: Mount `TenantSwitcher` in `Sidebar.vue`, hidden unless 2+ tenants

**Files:**
- Modify: `frontend/src/components/Sidebar.vue`
- Modify: `frontend/src/Sidebar.spec.ts`

**Interfaces:**
- Consumes: `TenantSwitcher.vue` (Task 3).
- Produces: new **optional** `Sidebar` props `tenants?: Tenant[]` and `activeTenantId?: number | null` (optional, per Global Constraints — no existing `mount(Sidebar, ...)` call anywhere needs updating); new emit `(e: 'switch-tenant', tenantId: number): void`. Task 5 (`App.vue`) consumes the emit.

- [ ] **Step 1: Write the failing tests**

Add to `frontend/src/Sidebar.spec.ts`, a new describe block (place it near the existing `describe('Sidebar workspace switcher', ...)` block):

```typescript
describe('Sidebar tenant switcher', () => {
  it('does not render the tenant switcher when given 0 tenants', () => {
    const wrapper = mount(Sidebar, {
      props: { notes: [], selectedNoteId: null, workspaceId: 1, folderPositions: [], workspaces: [], frontendVersion: 'dev' },
    })
    expect(wrapper.find('[data-testid="tenant-switcher-select"]').exists()).toBe(false)
  })

  it('does not render the tenant switcher when given exactly 1 tenant', () => {
    const wrapper = mount(Sidebar, {
      props: {
        notes: [], selectedNoteId: null, workspaceId: 1, folderPositions: [], workspaces: [], frontendVersion: 'dev',
        tenants: [{ id: 1, slug: 'acme', name: 'Acme Corp' }],
      },
    })
    expect(wrapper.find('[data-testid="tenant-switcher-select"]').exists()).toBe(false)
  })

  it('renders the tenant switcher when given 2+ tenants', () => {
    const wrapper = mount(Sidebar, {
      props: {
        notes: [], selectedNoteId: null, workspaceId: 1, folderPositions: [], workspaces: [], frontendVersion: 'dev',
        tenants: [
          { id: 1, slug: 'acme', name: 'Acme Corp' },
          { id: 2, slug: 'globex', name: 'Globex Inc' },
        ],
      },
    })
    expect(wrapper.findAll('[data-testid="tenant-switcher-select"] option')).toHaveLength(2)
  })

  it('emits switch-tenant when the switcher changes selection', async () => {
    const wrapper = mount(Sidebar, {
      props: {
        notes: [], selectedNoteId: null, workspaceId: 1, folderPositions: [], workspaces: [], frontendVersion: 'dev',
        tenants: [
          { id: 1, slug: 'acme', name: 'Acme Corp' },
          { id: 2, slug: 'globex', name: 'Globex Inc' },
        ],
        activeTenantId: 1,
      },
    })
    await wrapper.find('[data-testid="tenant-switcher-select"]').setValue('2')
    expect(wrapper.emitted('switch-tenant')![0]).toEqual([2])
  })
})
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./scripts/jt.sh npm -- run test -- Sidebar.spec.ts`
Expected: FAIL — `tenants`/`activeTenantId` props don't exist, no `TenantSwitcher` mounted, no `switch-tenant` emit.

- [ ] **Step 3: Add the props, emit, import, and mount point**

Add `Tenant` to the `import type { Workspace, ... } from '../services/types'` line at the top of `<script setup>`.

Add the import for the component, alongside the existing `import WorkspaceSwitcher from './WorkspaceSwitcher.vue'`:

```typescript
import TenantSwitcher from './TenantSwitcher.vue'
```

Add to the `defineProps` block (optional, per Global Constraints):

```typescript
  tenants?: Tenant[]
  activeTenantId?: number | null
```

Add to the `defineEmits` block:

```typescript
  (e: 'switch-tenant', tenantId: number): void
```

In the template, right before the existing `<WorkspaceSwitcher ...>` (line 15):

```vue
      <TenantSwitcher
        v-if="(props.tenants ?? []).length > 1"
        :tenants="props.tenants ?? []"
        :active-tenant-id="props.activeTenantId ?? null"
        @switch="(id) => emit('switch-tenant', id)"
      />
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./scripts/jt.sh npm -- run test -- Sidebar.spec.ts`
Expected: PASS, including the four new tests and all pre-existing ones (no pre-existing test needed changes, since the new props are optional).

- [ ] **Step 5: Commit**

```bash
git add frontend/src/components/Sidebar.vue frontend/src/Sidebar.spec.ts
git commit -m "feat: mount TenantSwitcher in Sidebar, hidden unless 2+ tenants"
```

---

### Task 5: Wire active-tenant state into `App.vue`

**Files:**
- Modify: `frontend/src/App.vue`
- Modify: `frontend/src/App.spec.ts`

**Interfaces:**
- Consumes: `getTenants()`/`getWorkspaces(tenantId?)` (Task 3), `Sidebar`'s `switch-tenant` emit (Task 4).
- Produces: nothing new consumed elsewhere — final integration point.

- [ ] **Step 1: Write the failing tests**

In `frontend/src/App.spec.ts`, add `getTenants` to the mock block (find the existing `vi.mock('./services/api', () => ({...}))` and add, alongside `getWorkspaces`):

```typescript
  getTenants: vi.fn().mockResolvedValue([
    { id: 1, slug: 'default', name: 'Default Tenant' }
  ]),
```

Add `getTenants` to the top-level import from `./services/api` (alongside `createNote, getWorkspaces, getAuthConfig`).

Add a new describe block, mirroring the workspace persistence tests:

```typescript
describe('App tenant switching and persistence', () => {
  it('does not fetch a tenant-scoped workspace list when there is only one tenant', async () => {
    vi.mocked(getTenants).mockResolvedValueOnce([
      { id: 1, slug: 'default', name: 'Default Tenant' },
    ])
    vi.mocked(getWorkspaces).mockResolvedValueOnce([
      { id: 1, tenant_id: 1, slug: 'main', name: 'Main' },
    ])

    const wrapper = mount(App)
    await flushPromises()

    expect(getWorkspaces).toHaveBeenCalledWith(undefined)
    expect(wrapper.findComponent({ name: 'Sidebar' }).props('tenants')).toEqual([
      { id: 1, slug: 'default', name: 'Default Tenant' },
    ])
  })

  it('resolves the active tenant from localStorage when there are 2+ tenants', async () => {
    vi.mocked(getTenants).mockResolvedValueOnce([
      { id: 1, slug: 'acme', name: 'Acme Corp' },
      { id: 2, slug: 'globex', name: 'Globex Inc' },
    ])
    vi.mocked(getWorkspaces).mockResolvedValueOnce([
      { id: 5, tenant_id: 2, slug: 'side', name: 'Side' },
    ])
    localStorage.setItem('jotter-active-tenant-id', '2')

    const wrapper = mount(App)
    await flushPromises()

    expect(getWorkspaces).toHaveBeenCalledWith(2)
    expect(wrapper.findComponent({ name: 'Sidebar' }).props('activeTenantId')).toBe(2)
  })

  it('falls back to the first tenant when the stored id is not in the list', async () => {
    vi.mocked(getTenants).mockResolvedValueOnce([
      { id: 1, slug: 'acme', name: 'Acme Corp' },
      { id: 2, slug: 'globex', name: 'Globex Inc' },
    ])
    vi.mocked(getWorkspaces).mockResolvedValueOnce([])
    localStorage.setItem('jotter-active-tenant-id', '999')

    const wrapper = mount(App)
    await flushPromises()

    expect(wrapper.findComponent({ name: 'Sidebar' }).props('activeTenantId')).toBe(1)
    expect(localStorage.getItem('jotter-active-tenant-id')).toBe('1')
  })

  it('persists the new tenant id and refetches workspaces when Sidebar emits switch-tenant', async () => {
    vi.mocked(getTenants).mockResolvedValueOnce([
      { id: 1, slug: 'acme', name: 'Acme Corp' },
      { id: 2, slug: 'globex', name: 'Globex Inc' },
    ])
    vi.mocked(getWorkspaces)
      .mockResolvedValueOnce([{ id: 5, tenant_id: 1, slug: 'main', name: 'Main' }])
      .mockResolvedValueOnce([{ id: 9, tenant_id: 2, slug: 'side', name: 'Side' }])

    const wrapper = mount(App)
    await flushPromises()

    await wrapper.findComponent({ name: 'Sidebar' }).vm.$emit('switch-tenant', 2)
    await flushPromises()

    expect(localStorage.getItem('jotter-active-tenant-id')).toBe('2')
    expect(getWorkspaces).toHaveBeenLastCalledWith(2)
    expect(wrapper.findComponent({ name: 'Sidebar' }).props('workspaceId')).toBe(9)
  })
})
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./scripts/jt.sh npm -- run test -- App.spec.ts`
Expected: FAIL — `getTenants` is never called, `tenants`/`activeTenantId` props never passed, no `switch-tenant` handler.

- [ ] **Step 3: Wire it into `App.vue`**

Add `getTenants` to the import list from `./services/api` (alongside `getAuthConfig`).

Add refs near `workspaces`/`activeWorkspaceId`:

```typescript
const tenants = ref<Tenant[]>([])
const activeTenantId = ref<number | null>(null)
```

Add `Tenant` to the `import type { Workspace, ... } from './services/types'` line.

Add the storage key constant near `WORKSPACE_STORAGE_KEY`:

```typescript
const TENANT_STORAGE_KEY = 'jotter-active-tenant-id'
```

Replace `initWorkspace()`'s current body:

```typescript
async function initWorkspace() {
  try {
    const list = await getWorkspaces()
    workspaces.value = list

    const stored = localStorage.getItem(WORKSPACE_STORAGE_KEY)
    const storedId = stored !== null ? Number(stored) : null
    const storedIsValid = storedId !== null && list.some((ws) => ws.id === storedId)

    if (storedIsValid) {
      activeWorkspaceId.value = storedId as number
    } else if (list.length > 0) {
      activeWorkspaceId.value = list[0].id
      localStorage.setItem(WORKSPACE_STORAGE_KEY, String(list[0].id))
    }

    await refreshNotesList()
    await refreshNotifications()
  } catch (err) {
    console.error('Failed to initialize workspace:', err)
  }
}
```

With:

```typescript
async function initWorkspace() {
  try {
    const tenantList = await getTenants()
    tenants.value = tenantList

    let scopeTenantId: number | undefined

    if (tenantList.length > 1) {
      const storedTenant = localStorage.getItem(TENANT_STORAGE_KEY)
      const storedTenantId = storedTenant !== null ? Number(storedTenant) : null
      const storedTenantIsValid = storedTenantId !== null && tenantList.some((t) => t.id === storedTenantId)

      if (storedTenantIsValid) {
        activeTenantId.value = storedTenantId as number
      } else {
        activeTenantId.value = tenantList[0].id
        localStorage.setItem(TENANT_STORAGE_KEY, String(tenantList[0].id))
      }

      scopeTenantId = activeTenantId.value
    }

    const list = await getWorkspaces(scopeTenantId)
    workspaces.value = list

    const stored = localStorage.getItem(WORKSPACE_STORAGE_KEY)
    const storedId = stored !== null ? Number(stored) : null
    const storedIsValid = storedId !== null && list.some((ws) => ws.id === storedId)

    if (storedIsValid) {
      activeWorkspaceId.value = storedId as number
    } else if (list.length > 0) {
      activeWorkspaceId.value = list[0].id
      localStorage.setItem(WORKSPACE_STORAGE_KEY, String(list[0].id))
    }

    await refreshNotesList()
    await refreshNotifications()
  } catch (err) {
    console.error('Failed to initialize workspace:', err)
  }
}
```

Add a new handler, near `handleSwitchWorkspace`:

```typescript
async function handleSwitchTenant(tenantId: number) {
  activeTenantId.value = tenantId
  localStorage.setItem(TENANT_STORAGE_KEY, String(tenantId))

  const list = await getWorkspaces(tenantId)
  workspaces.value = list

  if (list.length > 0) {
    activeWorkspaceId.value = list[0].id
    localStorage.setItem(WORKSPACE_STORAGE_KEY, String(list[0].id))
  }

  await refreshNotesList()
  await refreshNotifications()
}
```

Add the two new props and the new emit to the `<Sidebar>` invocation, alongside the existing `:workspaces="workspaces"`/`@switch-workspace="handleSwitchWorkspace"`:

```vue
      :tenants="tenants"
      :active-tenant-id="activeTenantId"
```

```vue
      @switch-tenant="handleSwitchTenant"
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./scripts/jt.sh npm -- run test -- App.spec.ts`
Expected: PASS, including the four new tests and all pre-existing ones (the pre-existing workspace-persistence tests' `getWorkspaces` mock calls still resolve correctly since the default `getTenants` mock returns a single-tenant list, matching today's behavior for every test that doesn't explicitly override it).

- [ ] **Step 5: Run the full frontend suite, then the full `jt.sh test`**

Run: `./scripts/jt.sh npm -- run test`
Expected: all pass.

Run: `./scripts/jt.sh test`
Expected: PHP suite passes, `vue-tsc -b && vite build` succeeds, vitest suite passes.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/App.vue frontend/src/App.spec.ts
git commit -m "feat: fetch and switch active tenant in App.vue"
```

---

### Task 6: Full-suite verification

**Files:** none (verification only)

- [ ] **Step 1: Run the complete test suite (PHP + frontend build + vitest)**

Run: `./scripts/jt.sh test`
Expected: exit 0, all PHP tests pass, `vue-tsc -b && vite build` succeeds with no type errors, all vitest tests pass.

- [ ] **Step 2: If green, proceed to `superpowers:finishing-a-development-branch`**

Push the branch, open a PR, and follow the established auto-merge-on-green-CI pattern from prior features in this project.
