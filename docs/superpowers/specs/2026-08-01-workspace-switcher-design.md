# Workspace Switcher Design

## Problem

Production Jotter shows no way to switch between workspaces. `App.vue` fetches
`GET /workspaces` and unconditionally selects `workspaces[0]`, and there is no
UI anywhere for a regular user to see other workspaces or switch between them.
Workspace management (create/archive/assign members) exists but only inside
`AdminPanel.vue`, which is admin-only.

The backend already models multi-workspace access per user (`Membership` rows
link a user to a tenant or to a specific workspace), but two things are
missing before a switcher can work correctly:

1. `GET /workspaces` (routes/api.php) returns every workspace in the database,
   unfiltered by the caller's memberships — a data-exposure bug independent of
   this feature, but one this feature must fix since the switcher's list comes
   from this endpoint.
2. There's no client-side UI to display or act on more than one workspace.

Tenant-level switching is out of scope for this feature (see "Out of Scope"
below) — this covers only switching between workspaces within the current
user's accessible set.

## Goals

- A user with `Membership` in more than one workspace can see all of them and
  switch which one is active, without a page reload.
- `GET /workspaces` only returns workspaces the requesting user actually has
  access to (via tenant-level or workspace-level `Membership`), except for
  admins, who continue to see all workspaces (matching the existing bypass in
  `AuthorizeWorkspaceAccess`).
- The active workspace choice persists across reloads and future logins on
  the same browser.

## Out of Scope

- Tenant switching (a user belonging to multiple tenants). Backend models
  support it, but no UI or endpoint work is included here — a future,
  separate cycle if ever needed.
- Creating or archiving workspaces from the switcher. That stays exclusively
  in `AdminPanel.vue`. The switcher is read/select only.
- Changing who has `Membership` in a workspace (also stays in `AdminPanel.vue`).

## Architecture

**Backend:** the workspace-listing endpoint (`GET /workspaces`, handled by
whichever controller action routes/api.php:34-41 points to) gains a
membership filter: non-admin callers get only workspaces where a `Membership`
row exists for their `subject_id`, matched either directly on `workspace_id`
or via `tenant_id` (mirroring the two-tier check already implemented in
`AuthorizeWorkspaceAccess`). Admins are unaffected and keep seeing every
workspace, since `AdminPanel.vue`'s workspace-management view depends on the
same endpoint.

**Frontend:** a new `WorkspaceSwitcher.vue` component renders a dropdown
listing the workspaces passed to it, highlighting the active one, and emits a
`switch` event with the chosen workspace's id. `Sidebar.vue` mounts it at the
very top, above the note tree, and simply forwards the emit upward.

`App.vue` owns the active-workspace state end to end:
- On mount, after fetching the (now-filtered) workspace list, it reads
  `localStorage['jotter-active-workspace-id']`.
- If that id is present in the fetched list, it becomes the active workspace.
  Otherwise (never set, or the user lost access to that workspace since last
  visit), `workspaces[0]` is used and localStorage is overwritten to match.
- On a `switch` event from `Sidebar`, `App.vue` updates its `activeWorkspaceId`
  ref, writes the new value to `localStorage` synchronously (same pattern as
  `useCollapsiblePanel` — no `watch()`, direct write inside the handler), and
  the existing `workspaceId` prop threading to `Sidebar`/`NoteEditor`/
  `CoverImageModal` naturally reloads workspace-scoped data (note tree,
  editor state) since those components already react to `workspaceId` prop
  changes.

No new persistence composable is needed — the localStorage read/write is a
handful of lines directly in `App.vue`, following the same synchronous-write
convention already established.

## Data Flow

1. User logs in → `App.vue` calls `GET /workspaces` → gets back only
   workspaces the user has `Membership` for (or all, if admin).
2. `App.vue` resolves `activeWorkspaceId` (localStorage → validate against
   list → fallback to first).
3. `Sidebar.vue` receives `workspaces` + `activeWorkspaceId`, renders
   `WorkspaceSwitcher` at the top.
4. User picks a different workspace in the dropdown → `WorkspaceSwitcher`
   emits `switch` → `Sidebar` re-emits → `App.vue` updates state + localStorage.
5. `workspaceId` prop change ripples down to `Sidebar`'s note tree fetch,
   `NoteEditor`, and `CoverImageModal`, all of which already key off this prop.

## Error Handling

- Only one workspace in the list: `WorkspaceSwitcher` still renders (shows the
  single workspace name, no dropdown interaction needed, or a disabled-looking
  single-item state) rather than being hidden — consistent visual anchor,
  simplest implementation, no conditional mounting logic in `Sidebar.vue`.
- Zero workspaces (a user with no memberships at all — shouldn't normally
  happen but is possible for a newly created account): out of scope for this
  feature; existing behavior (whatever `App.vue` does today when
  `workspaces` is empty) is unchanged.
- Stale localStorage value (workspace id no longer in the fetched list):
  silently falls back to `workspaces[0]`, overwrites localStorage — no error
  shown to the user, since this is a normal consequence of admin changing
  memberships, not a bug.

## Testing

- Backend: a feature test asserting a non-admin user's `GET /workspaces`
  response only includes workspaces they have `Membership` for (directly or
  via tenant), and a separate case confirming an admin still sees all
  workspaces regardless of `Membership` rows.
- Frontend:
  - `WorkspaceSwitcher.spec.ts` — renders one entry per workspace, highlights
    the active one, emits `switch` with the correct id on selection, renders
    correctly with only one workspace in the list.
  - `App.spec.ts` additions — resolves active workspace from localStorage
    when valid, falls back to `workspaces[0]` when the stored id isn't in the
    list, persists a new id to localStorage on switch.
