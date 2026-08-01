# Workspace Switcher Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a user with access to more than one workspace see all of them and switch which one is active, with the switch persisted across reloads — and fix `GET /workspaces` so it stops leaking every workspace in the database to non-admin users.

**Architecture:** Add an `accessibleWorkspaceIds()` method to the `IdentityProvider` contract (returns `null` for admins, meaning "all"; an array of ids otherwise), implement it in both `LocalIdentityProvider` and `GrandpaSSOnIdentityProvider`, and use it from a new `WorkspaceController@index` that replaces the current unfiltered closure route. On the frontend, add a `WorkspaceSwitcher.vue` dropdown mounted in `Sidebar.vue`'s header, with `App.vue` owning the active-workspace state and its `localStorage` persistence.

**Tech Stack:** Laravel 11 (PHP 8.2+), PHPUnit, Vue 3 `<script setup>` + TypeScript, Vitest + `@vue/test-utils`.

## Global Constraints

- Use only `./scripts/jt.sh` wrappers for all dependency/build/test/db commands (never call `php`, `composer`, `npm`, `docker` directly) — per repo `CLAUDE.md`/`AGENTS.md`.
- Tenant-level switching is explicitly out of scope — do not add any tenant-switching UI or endpoint.
- Workspace creation/archival stays exclusively in `AdminPanel.vue` — the switcher is read/select only, no create button.
- `useCollapsiblePanel`'s established convention applies: persistence writes to `localStorage` happen synchronously inside the mutating function itself — never via `watch()` (fires on a microtask, breaks synchronous test assertions).
- Match existing CSS variable names (`--color-surface`, `--color-border`, `--color-text`, `--color-text-muted`, `--color-hover`, `--space-2/3/4`, `--radius-md`, `--duration-standard`, `--ease-standard`) — do not invent new tokens.
- `v-show`-hidden elements are still found by `wrapper.find(...).exists()`/`wrapper.text()` — any "is it hidden" test must check `(el.element as HTMLElement).style.display === 'none'`, not `.exists()`.

---

### Task 1: `accessibleWorkspaceIds()` on the identity providers

**Files:**
- Modify: `app/Domain/Auth/Contracts/IdentityProvider.php`
- Modify: `app/Domain/Auth/Providers/LocalIdentityProvider.php`
- Modify: `app/Domain/Auth/Providers/GrandpaSSOnIdentityProvider.php`
- Test: `tests/Unit/LocalIdentityProviderWorkspaceAccessTest.php` (new)

**Interfaces:**
- Produces: `IdentityProvider::accessibleWorkspaceIds(AuthenticatedSubject $subject): ?array` — `null` means "no restriction, all workspaces" (admin); a `array<int>` is the exact set of workspace ids the subject may see. Task 2 consumes this directly.

- [ ] **Step 1: Write the failing unit test**

Create `tests/Unit/LocalIdentityProviderWorkspaceAccessTest.php`:

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

class LocalIdentityProviderWorkspaceAccessTest extends TestCase
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

        $this->assertNull($provider->accessibleWorkspaceIds($subject));
    }

    public function test_non_admin_sees_only_directly_assigned_workspace(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);
        $wsA = Workspace::create(['tenant_id' => $tenant->id, 'slug' => 'a', 'name' => 'A', 'vault_path' => storage_path('app/vaults/ws_a')]);
        $wsB = Workspace::create(['tenant_id' => $tenant->id, 'slug' => 'b', 'name' => 'B', 'vault_path' => storage_path('app/vaults/ws_b')]);

        Membership::create([
            'subject_id' => (string) $user->id,
            'tenant_id' => $tenant->id,
            'workspace_id' => $wsA->id,
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
        $ids = $provider->accessibleWorkspaceIds($subject);

        $this->assertIsArray($ids);
        $this->assertEqualsCanonicalizing([$wsA->id], $ids);
    }

    public function test_tenant_wide_membership_sees_every_workspace_in_tenant(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);
        $wsA = Workspace::create(['tenant_id' => $tenant->id, 'slug' => 'a', 'name' => 'A', 'vault_path' => storage_path('app/vaults/ws_a2')]);
        $wsB = Workspace::create(['tenant_id' => $tenant->id, 'slug' => 'b', 'name' => 'B', 'vault_path' => storage_path('app/vaults/ws_b2')]);

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
        $ids = $provider->accessibleWorkspaceIds($subject);

        $this->assertEqualsCanonicalizing([$wsA->id, $wsB->id], $ids);
    }

    public function test_user_with_no_membership_sees_nothing(): void
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

        $this->assertSame([], $provider->accessibleWorkspaceIds($subject));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./scripts/jt.sh test -- --filter=LocalIdentityProviderWorkspaceAccessTest`
Expected: FAIL with "Call to undefined method LocalIdentityProvider::accessibleWorkspaceIds()"

- [ ] **Step 3: Add the method to the contract**

In `app/Domain/Auth/Contracts/IdentityProvider.php`, add after `isAuthorizedForWorkspace`:

```php
    /**
     * Return the workspace ids the subject may access, or null if unrestricted (e.g. an admin).
     *
     * @return array<int>|null
     */
    public function accessibleWorkspaceIds(AuthenticatedSubject $subject): ?array;
```

- [ ] **Step 4: Implement in `LocalIdentityProvider`**

In `app/Domain/Auth/Providers/LocalIdentityProvider.php`, add this method (mirrors the `$subjectIds` resolution already used in `isAuthorizedForWorkspace`):

```php
    public function accessibleWorkspaceIds(AuthenticatedSubject $subject): ?array
    {
        if ($subject->isAdmin) {
            return null;
        }

        $subjectIds = array_filter([
            $subject->subjectId,
            (string) $subject->user?->id,
            $subject->email,
        ]);

        if ($subject->user) {
            $identitySubjectIds = $subject->user->identities()->pluck('subject_id')->all();
            $subjectIds = array_unique(array_merge($subjectIds, $identitySubjectIds));
        }

        $memberships = Membership::query()
            ->whereIn('subject_id', $subjectIds)
            ->get(['tenant_id', 'workspace_id']);

        $directWorkspaceIds = $memberships->whereNotNull('workspace_id')->pluck('workspace_id')->all();
        $tenantWideTenantIds = $memberships->whereNull('workspace_id')->pluck('tenant_id')->all();

        $tenantWideWorkspaceIds = $tenantWideTenantIds !== []
            ? Workspace::query()->whereIn('tenant_id', $tenantWideTenantIds)->pluck('id')->all()
            : [];

        return array_values(array_unique(array_merge($directWorkspaceIds, $tenantWideWorkspaceIds)));
    }
```

- [ ] **Step 5: Implement in `GrandpaSSOnIdentityProvider`**

In `app/Domain/Auth/Providers/GrandpaSSOnIdentityProvider.php`, add (mirrors how `isAuthorizedForWorkspace` delegates):

```php
    public function accessibleWorkspaceIds(AuthenticatedSubject $subject): ?array
    {
        if ($subject->isAdmin) {
            return null;
        }

        return $this->localProvider->accessibleWorkspaceIds($subject);
    }
```

- [ ] **Step 6: Run test to verify it passes**

Run: `./scripts/jt.sh test -- --filter=LocalIdentityProviderWorkspaceAccessTest`
Expected: PASS (4 tests)

- [ ] **Step 7: Commit**

```bash
git add app/Domain/Auth/Contracts/IdentityProvider.php app/Domain/Auth/Providers/LocalIdentityProvider.php app/Domain/Auth/Providers/GrandpaSSOnIdentityProvider.php tests/Unit/LocalIdentityProviderWorkspaceAccessTest.php
git commit -m "feat: add accessibleWorkspaceIds to IdentityProvider"
```

---

### Task 2: `WorkspaceController@index` filtered by membership

**Files:**
- Create: `app/Http/Controllers/WorkspaceController.php`
- Modify: `routes/api.php:34-41` (replace the closure route)
- Test: `tests/Feature/WorkspaceIndexTest.php` (new)

**Interfaces:**
- Consumes: `IdentityProvider::accessibleWorkspaceIds()` from Task 1; the `authenticated_subject` request attribute set by `AuthorizeWorkspaceAccess` middleware (already runs on this route since it's inside the `workspace.authorization` group).
- Produces: `GET /api/workspaces` response shape is unchanged (`{"data": [{id, tenant_id, slug, name}, ...]}`) — Task 5's `getWorkspaces()` frontend call needs no changes.

- [ ] **Step 1: Write the failing feature test**

Create `tests/Feature/WorkspaceIndexTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admin_only_sees_workspaces_they_have_membership_for(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);
        $wsA = Workspace::create(['tenant_id' => $tenant->id, 'slug' => 'a', 'name' => 'A', 'vault_path' => storage_path('app/vaults/idx_a')]);
        $wsB = Workspace::create(['tenant_id' => $tenant->id, 'slug' => 'b', 'name' => 'B', 'vault_path' => storage_path('app/vaults/idx_b')]);

        Membership::create([
            'subject_id' => (string) $user->id,
            'tenant_id' => $tenant->id,
            'workspace_id' => $wsA->id,
            'role' => 'editor',
        ]);

        $res = $this->actingAs($user)->getJson('/api/workspaces');

        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$wsA->id], $ids);
    }

    public function test_admin_sees_all_workspaces(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);
        $wsA = Workspace::create(['tenant_id' => $tenant->id, 'slug' => 'a', 'name' => 'A', 'vault_path' => storage_path('app/vaults/idx_a2')]);
        $wsB = Workspace::create(['tenant_id' => $tenant->id, 'slug' => 'b', 'name' => 'B', 'vault_path' => storage_path('app/vaults/idx_b2')]);

        $res = $this->actingAs($admin)->getJson('/api/workspaces');

        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$wsA->id, $wsB->id], $ids);
    }

    public function test_user_with_no_membership_sees_empty_list(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        Tenant::create(['slug' => 'acme', 'name' => 'Acme']);

        $res = $this->actingAs($user)->getJson('/api/workspaces');

        $res->assertOk();
        $this->assertSame([], $res->json('data'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./scripts/jt.sh test -- --filter=WorkspaceIndexTest`
Expected: FAIL — `test_non_admin_only_sees_workspaces_they_have_membership_for` and the no-membership test fail because the current closure returns every workspace unfiltered.

- [ ] **Step 3: Create `WorkspaceController`**

Create `app/Http/Controllers/WorkspaceController.php`:

```php
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
```

- [ ] **Step 4: Replace the route**

In `routes/api.php`, remove the closure at lines 34-41:

```php
    Route::get('/workspaces', function () {
        return response()->json([
            'data' => Workspace::query()
                ->select(['id', 'tenant_id', 'slug', 'name'])
                ->orderBy('id')
                ->get(),
        ]);
    });
```

Replace with:

```php
    Route::get('/workspaces', [\App\Http\Controllers\WorkspaceController::class, 'index']);
```

The `use App\Models\Workspace;` import at the top of `routes/api.php` can stay (still used elsewhere in the file) — do not remove it without checking other usages first.

- [ ] **Step 5: Run test to verify it passes**

Run: `./scripts/jt.sh test -- --filter=WorkspaceIndexTest`
Expected: PASS (3 tests)

- [ ] **Step 6: Run the full PHP suite to check for regressions**

Run: `./scripts/jt.sh test`
Expected: all PHP tests pass, including the pre-existing `AdminWorkspaceCrudTest` and any other test hitting `/api/workspaces`.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/WorkspaceController.php routes/api.php tests/Feature/WorkspaceIndexTest.php
git commit -m "fix: filter GET /workspaces by caller's membership"
```

---

### Task 3: `WorkspaceSwitcher.vue` component

**Files:**
- Create: `frontend/src/components/WorkspaceSwitcher.vue`
- Test: `frontend/src/WorkspaceSwitcher.spec.ts` (new)

**Interfaces:**
- Consumes: `Workspace` type from `frontend/src/services/types.ts` (`{id: number, tenant_id: number, slug: string, name: string}`).
- Produces: props `{workspaces: Workspace[], activeWorkspaceId: number | null}`; emits `(e: 'switch', workspaceId: number): void`. Task 4 mounts this in `Sidebar.vue` and wires the emit.

- [ ] **Step 1: Write the failing component test**

Create `frontend/src/WorkspaceSwitcher.spec.ts`:

```typescript
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import WorkspaceSwitcher from './components/WorkspaceSwitcher.vue'

const workspaces = [
  { id: 1, tenant_id: 1, slug: 'main', name: 'Main Workspace' },
  { id: 2, tenant_id: 1, slug: 'side', name: 'Side Project' },
]

describe('WorkspaceSwitcher', () => {
  it('renders one option per workspace', () => {
    const wrapper = mount(WorkspaceSwitcher, { props: { workspaces, activeWorkspaceId: 1 } })
    const options = wrapper.findAll('option')
    expect(options).toHaveLength(2)
    expect(options[0].text()).toBe('Main Workspace')
    expect(options[1].text()).toBe('Side Project')
  })

  it('selects the active workspace by default', () => {
    const wrapper = mount(WorkspaceSwitcher, { props: { workspaces, activeWorkspaceId: 2 } })
    expect((wrapper.find('select').element as HTMLSelectElement).value).toBe('2')
  })

  it('emits switch with the chosen workspace id', async () => {
    const wrapper = mount(WorkspaceSwitcher, { props: { workspaces, activeWorkspaceId: 1 } })
    await wrapper.find('select').setValue('2')
    expect(wrapper.emitted('switch')![0]).toEqual([2])
  })

  it('renders correctly with a single workspace', () => {
    const wrapper = mount(WorkspaceSwitcher, {
      props: { workspaces: [workspaces[0]], activeWorkspaceId: 1 },
    })
    expect(wrapper.findAll('option')).toHaveLength(1)
    expect(wrapper.find('select').exists()).toBe(true)
  })
})
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./scripts/jt.sh npm -- run test -- WorkspaceSwitcher.spec.ts`
Expected: FAIL — `Failed to resolve import "./components/WorkspaceSwitcher.vue"`

- [ ] **Step 3: Create the component**

Create `frontend/src/components/WorkspaceSwitcher.vue`:

```vue
<template>
  <div class="workspace-switcher">
    <select
      class="workspace-switcher-select"
      data-testid="workspace-switcher-select"
      :value="activeWorkspaceId ?? undefined"
      aria-label="Switch workspace"
      @change="handleChange"
    >
      <option v-for="ws in workspaces" :key="ws.id" :value="ws.id">{{ ws.name }}</option>
    </select>
  </div>
</template>

<script setup lang="ts">
import type { Workspace } from '../services/types'

defineProps<{
  workspaces: Workspace[]
  activeWorkspaceId: number | null
}>()

const emit = defineEmits<{
  (e: 'switch', workspaceId: number): void
}>()

function handleChange(event: Event) {
  const value = Number((event.target as HTMLSelectElement).value)
  emit('switch', value)
}
</script>

<style scoped>
.workspace-switcher {
  padding: 0 var(--space-2);
}

.workspace-switcher-select {
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

.workspace-switcher-select:hover {
  background: var(--color-hover);
}
</style>
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./scripts/jt.sh npm -- run test -- WorkspaceSwitcher.spec.ts`
Expected: PASS (4 tests)

- [ ] **Step 5: Commit**

```bash
git add frontend/src/components/WorkspaceSwitcher.vue frontend/src/WorkspaceSwitcher.spec.ts
git commit -m "feat: add WorkspaceSwitcher component"
```

---

### Task 4: Mount `WorkspaceSwitcher` in `Sidebar.vue`

**Files:**
- Modify: `frontend/src/components/Sidebar.vue`
- Modify: `frontend/src/Sidebar.spec.ts` (existing — add cases)

**Interfaces:**
- Consumes: `WorkspaceSwitcher.vue` (Task 3) props/emit; existing `workspaceId?: number | null` prop already on `Sidebar` (used elsewhere for note-tree fetches) is NOT reused for this — a new `workspaces: Workspace[]` prop is added since `Sidebar` currently has no full list, only the single active id.
- Produces: new `Sidebar` prop `workspaces: Workspace[]` (required — pass `[]` from callers that don't have the list yet, e.g. existing specs); new emit `(e: 'switch-workspace', workspaceId: number): void`. Task 5's `App.vue` consumes this emit.

- [ ] **Step 1: Write the failing test**

In `frontend/src/Sidebar.spec.ts`, find the existing default `props` object used across most `mount(Sidebar, ...)` calls (it already includes `notes`, `selectedNoteId`, etc.) and add `workspaces: []` to it so existing tests keep compiling once the prop becomes required. Then add:

```typescript
it('renders the workspace switcher with the provided workspaces', () => {
  const wrapper = mount(Sidebar, {
    props: {
      ...defaultProps, // use whatever the file's existing base-props object/spread is named
      workspaces: [
        { id: 1, tenant_id: 1, slug: 'main', name: 'Main Workspace' },
        { id: 2, tenant_id: 1, slug: 'side', name: 'Side Project' },
      ],
      workspaceId: 1,
    },
  })
  expect(wrapper.findAll('[data-testid="workspace-switcher-select"] option')).toHaveLength(2)
})

it('emits switch-workspace when the switcher changes selection', async () => {
  const wrapper = mount(Sidebar, {
    props: {
      ...defaultProps,
      workspaces: [
        { id: 1, tenant_id: 1, slug: 'main', name: 'Main Workspace' },
        { id: 2, tenant_id: 1, slug: 'side', name: 'Side Project' },
      ],
      workspaceId: 1,
    },
  })
  await wrapper.find('[data-testid="workspace-switcher-select"]').setValue('2')
  expect(wrapper.emitted('switch-workspace')![0]).toEqual([2])
})
```

Note: read the actual top of `frontend/src/Sidebar.spec.ts` first to find the exact name of its shared base-props object (or inline the full existing props if the file doesn't use a shared object) before writing this step — match the file's real structure rather than assuming `defaultProps` exists verbatim.

- [ ] **Step 2: Run test to verify it fails**

Run: `./scripts/jt.sh npm -- run test -- Sidebar.spec.ts`
Expected: FAIL — element `[data-testid="workspace-switcher-select"]` not found; TS error on missing `workspaces` prop once added to type.

- [ ] **Step 3: Add the prop, emit, and mount point**

In `frontend/src/components/Sidebar.vue`, add to the `defineProps` block (around line 384-393):

```typescript
const props = defineProps<{
  notes: NoteMeta[]
  selectedNoteId: number | null
  currentUser?: AuthUser | null
  notifications?: NotificationItem[]
  isMobileSidebarOpen?: boolean
  workspaceId?: number | null
  workspaces: Workspace[]
  folderPositions?: FolderPosition[]
  revealFolderRequest?: { path: string; nonce: number } | null
}>()
```

Add to the `defineEmits` block (around line 395-416), anywhere in the list:

```typescript
  (e: 'switch-workspace', workspaceId: number): void
```

Add the import at the top of the `<script setup>` block:

```typescript
import type { Workspace } from '../services/types'
import WorkspaceSwitcher from './WorkspaceSwitcher.vue'
```

In the template, inside `.sidebar-header`, right after the closing `</div>` of `.brand` (after line 13) and before `.header-actions` (before line 15):

```vue
      <WorkspaceSwitcher
        :workspaces="props.workspaces"
        :active-workspace-id="props.workspaceId ?? null"
        @switch="(id) => emit('switch-workspace', id)"
      />
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./scripts/jt.sh npm -- run test -- Sidebar.spec.ts`
Expected: PASS, including the two new tests and all pre-existing ones.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/components/Sidebar.vue frontend/src/Sidebar.spec.ts
git commit -m "feat: mount WorkspaceSwitcher in Sidebar"
```

---

### Task 5: Wire active-workspace state and persistence into `App.vue`

**Files:**
- Modify: `frontend/src/App.vue`
- Modify: `frontend/src/App.spec.ts` (existing — add cases)

**Interfaces:**
- Consumes: `Sidebar`'s new `switch-workspace` emit (Task 4); `getWorkspaces()` from `frontend/src/services/api.ts` (unchanged signature — Task 2 kept the response shape identical).
- Produces: `activeWorkspaceId` continues to be the single source of truth threaded to `Sidebar`/`NoteEditor`/`CoverImageModal` exactly as today — no changes needed in those consumers.

- [ ] **Step 1: Write the failing tests**

In `frontend/src/App.spec.ts`, add (adjust the existing `vi.mock('./services/api', ...)` block's `getWorkspaces` mock per test as needed, and add `beforeEach(() => localStorage.clear())` if not already present):

```typescript
it('uses the workspace id from localStorage when it is in the fetched list', async () => {
  vi.mocked(getWorkspaces).mockResolvedValueOnce([
    { id: 1, tenant_id: 1, slug: 'main', name: 'Main' },
    { id: 2, tenant_id: 1, slug: 'side', name: 'Side' },
  ])
  localStorage.setItem('jotter-active-workspace-id', '2')

  const wrapper = mount(App)
  await flushPromises()

  expect(wrapper.findComponent({ name: 'Sidebar' }).props('workspaceId')).toBe(2)
})

it('falls back to the first workspace when the stored id is not in the list', async () => {
  vi.mocked(getWorkspaces).mockResolvedValueOnce([
    { id: 1, tenant_id: 1, slug: 'main', name: 'Main' },
  ])
  localStorage.setItem('jotter-active-workspace-id', '999')

  const wrapper = mount(App)
  await flushPromises()

  expect(wrapper.findComponent({ name: 'Sidebar' }).props('workspaceId')).toBe(1)
  expect(localStorage.getItem('jotter-active-workspace-id')).toBe('1')
})

it('persists the new workspace id when Sidebar emits switch-workspace', async () => {
  vi.mocked(getWorkspaces).mockResolvedValueOnce([
    { id: 1, tenant_id: 1, slug: 'main', name: 'Main' },
    { id: 2, tenant_id: 1, slug: 'side', name: 'Side' },
  ])

  const wrapper = mount(App)
  await flushPromises()

  await wrapper.findComponent({ name: 'Sidebar' }).vm.$emit('switch-workspace', 2)

  expect(localStorage.getItem('jotter-active-workspace-id')).toBe('2')
  expect(wrapper.findComponent({ name: 'Sidebar' }).props('workspaceId')).toBe(2)
})
```

Check the top of `frontend/src/App.spec.ts` for whatever helper it already uses to await async mount effects (`flushPromises`, `await nextTick()` twice, or similar) and use that same helper rather than introducing a new one.

- [ ] **Step 2: Run test to verify it fails**

Run: `./scripts/jt.sh npm -- run test -- App.spec.ts`
Expected: FAIL — `activeWorkspaceId` always resolves to `list[0].id`, ignoring localStorage; no handler exists for a `switch-workspace` emit.

- [ ] **Step 3: Update `initWorkspace` and add the switch handler**

In `frontend/src/App.vue`, replace the `initWorkspace` function (lines 328-340):

```typescript
const WORKSPACE_STORAGE_KEY = 'jotter-active-workspace-id'

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

function handleSwitchWorkspace(workspaceId: number) {
  activeWorkspaceId.value = workspaceId
  localStorage.setItem(WORKSPACE_STORAGE_KEY, String(workspaceId))
  refreshNotesList()
}
```

Add `workspaces` prop and `@switch-workspace` handler to the `<Sidebar>` invocation (near line 21-50):

```vue
    <Sidebar
      :notes="notes"
      :selected-note-id="activeNoteId"
      :current-user="currentUser"
      :notifications="notifications"
      :is-mobile-sidebar-open="isMobileSidebarOpen"
      :workspace-id="activeWorkspaceId"
      :workspaces="workspaces"
      :folder-positions="folderPositions"
      :reveal-folder-request="revealFolderRequest"
      @switch-workspace="handleSwitchWorkspace"
      @notes-reordered="refreshNotesList"
      ...
```

(keep every other existing prop/emit on `<Sidebar>` untouched — only `:workspaces` and `@switch-workspace` are new).

- [ ] **Step 4: Run test to verify it passes**

Run: `./scripts/jt.sh npm -- run test -- App.spec.ts`
Expected: PASS, including the three new tests and all pre-existing ones.

- [ ] **Step 5: Run the full frontend suite for regressions**

Run: `./scripts/jt.sh npm -- run test`
Expected: all frontend tests pass (no regressions in `Sidebar.spec.ts`, `WorkspaceSwitcher.spec.ts`, `App.spec.ts`, or elsewhere).

- [ ] **Step 6: Commit**

```bash
git add frontend/src/App.vue frontend/src/App.spec.ts
git commit -m "feat: persist active workspace selection via localStorage"
```

---

### Task 6: Full-suite verification

**Files:** none (verification only)

- [ ] **Step 1: Run the complete test suite (PHP + frontend build + vitest)**

Run: `./scripts/jt.sh test`
Expected: exit 0, all PHP tests pass, `vue-tsc -b && vite build` succeeds with no type errors, all vitest tests pass.

- [ ] **Step 2: If green, proceed to `superpowers:finishing-a-development-branch`**

Push the branch, open a PR, and follow the established auto-merge-on-green-CI pattern from prior features in this project.
