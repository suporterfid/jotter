# Tenant Switcher Design

## Problem

The backend already models multi-tenancy — `Tenant` `hasMany` `Workspace`, and `Membership` rows link a user (`subject_id`) to a `tenant_id` and optionally a specific `workspace_id` (null `workspace_id` means tenant-wide access). A single user CAN, per the schema, have `Membership` rows across two different tenants. But there is zero UI or API surface to discover or switch which tenant is active: `GET /api/workspaces` returns workspaces across ALL of a user's tenants mixed together (filtered only by `IdentityProvider::accessibleWorkspaceIds()`), with no tenant-level scoping at all.

This was explicitly deferred as its own cycle when the workspace switcher (single-tenant scoped) shipped (PR #246), following the same bug report that first surfaced the missing workspace switcher.

## Goals

- A user with `Membership` in 2+ tenants can see and switch which tenant is active.
- Switching tenants filters the workspace list (and switcher) to that tenant's workspaces.
- Users with access to only one tenant (the common case today — confirmed via codebase research that no seeder or test currently exercises multi-tenant-per-user) see no new UI at all; behavior is unchanged for them.

## Out of Scope

- Creating/archiving tenants, or assigning a user's tenant membership from this UI — stays wherever tenant/membership administration already happens (currently: direct `Membership::create()`, no admin UI for tenant-level membership exists yet, and building one is not part of this feature).
- Any edge-case UX for "switched to a tenant with zero workspaces" beyond what the existing workspace switcher already does for an empty list (unchanged, not extended here).
- Any change to how admins operate — admins already see all workspaces regardless of tenant (`accessibleWorkspaceIds()` returns `null` for them); this feature adds an equivalent `accessibleTenantIds()` with the same admin bypass, but does not add an admin-facing tenant management page.

## Architecture

**Backend:** `IdentityProvider` gains `accessibleTenantIds(AuthenticatedSubject $subject): ?array` (null = unrestricted/admin, array = exact accessible tenant ids), implemented in both `LocalIdentityProvider` and `GrandpaSSOnIdentityProvider` exactly mirroring the existing `accessibleWorkspaceIds()` admin-bypass pattern. Since `isAuthorizedForWorkspace()` and `accessibleWorkspaceIds()` in `LocalIdentityProvider` already duplicate the same `$subjectIds` resolution logic (subject id, user id, email, plus linked identities), this task extracts that into a shared private helper method — `accessibleTenantIds()` becomes the third consumer, making the extraction worth doing now rather than a third copy-paste.

A new `TenantController@index` (`GET /api/tenants`, same `workspace.authorization` middleware group as the workspaces route) returns `{"data": [{id, slug, name}, ...]}`, filtered by `accessibleTenantIds()` exactly like `WorkspaceController@index` filters by `accessibleWorkspaceIds()`.

`WorkspaceController@index` gains an optional `tenant_id` query parameter (`Route::get('/workspaces', ...)`, read via `$request->query('tenant_id')`). When present, the existing `accessibleIds`-filtered query gets an additional `->where('tenant_id', $tenantId)` clause. No new authorization check is needed for this parameter specifically: `accessibleWorkspaceIds()` already constrains the result to workspaces the caller can see, so an inaccessible or nonexistent `tenant_id` simply intersects to an empty set rather than requiring a separate 403 path.

**Frontend:** A `TenantSwitcher.vue` component (structurally identical to `WorkspaceSwitcher.vue` — a `<select>` dropdown, props `{tenants: Tenant[], activeTenantId: number | null}`, emits `switch(tenantId: number)`) mounts in `Sidebar.vue` directly above the existing `WorkspaceSwitcher`, but ONLY when `tenants.length > 1` — a `v-if` in `Sidebar.vue`'s template, not inside `TenantSwitcher.vue` itself (keeping the component simple; the visibility rule lives with its caller, same separation already used for the User Profile Footer's `v-if="currentUser"`).

`App.vue` fetches `getTenants()` alongside `getWorkspaces()` on boot. If there's more than one tenant: resolve `activeTenantId` from `localStorage` (key `jotter-active-tenant-id`, same validate-against-list-then-fallback-to-first pattern as `activeWorkspaceId`), and call `getWorkspaces(activeTenantId)` — the existing `getWorkspaces()` function gains an optional `tenantId` parameter that, when passed, appends `?tenant_id=` to the request. If there's zero or one tenant, `activeTenantId` stays `null` and `getWorkspaces()` is called with no argument, exactly as today — the single-tenant code path is untouched.

Switching tenants (`TenantSwitcher`'s `switch` event, forwarded by `Sidebar` as `switch-tenant`, handled in `App.vue`) persists the new `activeTenantId` to `localStorage`, refetches `getWorkspaces(newTenantId)`, and re-resolves `activeWorkspaceId` within that new list using the same validate-or-fallback-to-first logic `initWorkspace()` already uses — a workspace from the old tenant will not exist in the new tenant's list, so it always falls through to "pick the first" rather than needing a dedicated reset step.

## Error Handling

- Zero or one tenant: no switcher renders, no new state, no behavior change — this is the default/common path and must stay indistinguishable from pre-feature behavior.
- Switching to a tenant with zero workspaces: same as the existing (already out-of-scope, unchanged) behavior for an empty workspace list — not a new error state introduced by this feature.
- `?tenant_id=` naming an inaccessible or nonexistent tenant: empty `data` array from `GET /api/workspaces`, not a 403 — consistent with how the endpoint already silently scopes to only-accessible results rather than erroring.

## Testing

- Backend: feature tests for `GET /api/tenants` (non-admin sees only their tenants, admin sees all, zero-membership user sees an empty list — mirroring `WorkspaceIndexTest`'s three cases exactly), and feature tests for `GET /api/workspaces?tenant_id=X` (scopes correctly to that tenant, an inaccessible/nonexistent tenant id yields an empty list rather than an error, omitting the parameter preserves today's unscoped-across-tenants behavior).
- Frontend: `TenantSwitcher.spec.ts` mirroring `WorkspaceSwitcher.spec.ts`'s four cases (renders one option per tenant, selects the active one, emits `switch` with the chosen id, renders correctly with a single tenant in the list — note this component-level test still covers the single-tenant render since the *hiding* decision lives in `Sidebar.vue`, not here). `Sidebar.spec.ts` additions: does not render the tenant switcher when given 0 or 1 tenants, renders and forwards `switch-tenant` when given 2+. `App.spec.ts` additions: resolves `activeTenantId` from `localStorage` when valid and multiple tenants exist, falls back to the first tenant when the stored id isn't in the list, persists a new tenant id and refetches/reset-resolves `activeWorkspaceId` on `switch-tenant`, and does not call `getTenants`'s multi-tenant logic path differently when there's only one tenant (single-tenant boot fetches workspaces with no `tenant_id` argument, matching current behavior).
