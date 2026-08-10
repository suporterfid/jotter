# Frontend test warning cleanup — design

## Goal

Remove the known Vue Teleport and incomplete API-mock warnings from normal frontend test runs without weakening component coverage or changing existing behavioral assertions.

## Context

`NoteEditor` renders right-side drawers through the `#app-right-drawer` Teleport target. Some test groups create that element manually, but many other mounts do not, producing repeated Vue warnings. Separately, `App.spec.ts` uses a full mock of `services/api` that omits `getNotifications`, which `App.vue` calls during initialization.

## Decision

Use the shared Vitest setup file as the lifecycle owner of the right-drawer target. It will append one `#app-right-drawer` element before every test and remove it after every test. The manual target setup and teardown in `NoteEditor.spec.ts` will be removed so there is never a duplicate target or cleanup race.

`App.spec.ts` will add an explicit `getNotifications` mock returning an empty array. It represents the real initial empty-notification state and allows `App.vue` initialization to run without a missing-export error or a network request.

## Scope

- Modify only the frontend shared test setup, the redundant `NoteEditor` local setup, and the `App.spec.ts` API mock.
- Keep drawer tests mounted against a real Teleport target; do not globally stub Teleport.
- Preserve all existing assertions unless they depend on the absent test fixture.

## Lifecycle and isolation

The shared setup owns both creation and removal of the DOM fixture. `beforeEach` removes a stale target defensively, then creates exactly one new target. `afterEach` removes it, keeping jsdom state isolated across tests and preventing one test's drawer contents from affecting another.

The App API mock remains local to `App.spec.ts`; it explicitly lists every service export used during the App mount, including `getNotifications`.

## Verification

Run the complete frontend suite using the repository Docker command. It must exit successfully with no occurrences of:

- `Failed to locate Teleport target with selector "#app-right-drawer"`
- `Invalid Teleport target on mount`
- `No "getNotifications" export is defined`

Run the full `scripts/jt.ps1 test` suite afterward to ensure the shared setup does not affect backend or integrated test execution.

## Non-goals

- Fixing unrelated dependency, chunk-size, or other test warnings.
- Reworking the application Teleport architecture.
- Replacing feature-level API mocks with a new global mocking framework.

## Self-review

The ownership boundary is explicit: shared setup owns DOM fixtures and the App spec owns its local API behavior. The design preserves real Teleport coverage, isolates each test, and targets only the two warning classes in #340.
