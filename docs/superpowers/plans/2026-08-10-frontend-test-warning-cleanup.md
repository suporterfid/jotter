# Frontend Test Warning Cleanup Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (- [ ]) syntax for tracking.

**Goal:** Make the frontend suite run without the known right-drawer Teleport and getNotifications mock warnings.

**Architecture:** The shared Vitest setup owns exactly one DOM Teleport target per test lifecycle. App.spec.ts owns a complete local API mock for the exports App.vue calls at mount time. Tests continue to mount real Teleports and real App initialization paths.

**Tech Stack:** Vue 3, Vue Test Utils, Vitest, jsdom, TypeScript, Docker Compose via scripts/jt.ps1.

## Global Constraints

- Modify only the shared setup, redundant NoteEditor local fixture lifecycle, and App.spec.ts API mock.
- Do not stub Teleport globally or alter application production code.
- Preserve existing behavioral assertions.
- Include getNotifications in the local App API mock with an empty-array response.
- Run frontend tooling only through scripts/jt.ps1.
- The completed frontend run contains none of the two Teleport warning strings or the missing getNotifications-export string.

---

## File structure

| File | Responsibility |
| --- | --- |
| frontend/src/test-setup.ts | Shared jsdom fixture lifecycle for #app-right-drawer. |
| frontend/src/test-setup.spec.ts | Regression assertion that every Vitest test receives the Teleport target. |
| frontend/src/NoteEditor.spec.ts | Drawer tests consume the shared fixture rather than owning local duplicate fixtures. |
| frontend/src/App.spec.ts | Complete local mock of API exports used during App initialization. |

### Task 1: Give every component test a real right-drawer Teleport target

**Files:**
- Create: frontend/src/test-setup.spec.ts
- Modify: frontend/src/test-setup.ts
- Modify: frontend/src/NoteEditor.spec.ts

**Interfaces:**
- Consumes: Vitest setupFiles configured as ./src/test-setup.ts in frontend/vite.config.ts.
- Produces: one document element with id app-right-drawer before each test and no such element after each test.

- [ ] **Step 1: Write the failing shared-setup regression test**

Create frontend/src/test-setup.spec.ts:

~~~ts
import { describe, expect, it } from 'vitest'

describe('shared Vitest setup', () => {
  it('provides the right drawer Teleport target', () => {
    expect(document.getElementById('app-right-drawer')).not.toBeNull()
  })
})
~~~

- [ ] **Step 2: Run the target test to verify the fixture is absent**

Run:

~~~powershell
.\scripts\jt.ps1 npm test -- src/test-setup.spec.ts
~~~

Expected: FAIL because test-setup.ts does not yet create #app-right-drawer.

- [ ] **Step 3: Implement the shared fixture lifecycle**

In frontend/src/test-setup.ts, import beforeEach and afterEach from vitest. Keep the existing i18n configuration. Add:

~~~ts
function removeRightDrawerTarget() {
  document.getElementById('app-right-drawer')?.remove()
}

beforeEach(() => {
  removeRightDrawerTarget()

  const target = document.createElement('div')
  target.id = 'app-right-drawer'
  document.body.append(target)
})

afterEach(() => {
  removeRightDrawerTarget()
})
~~~

Remove each redundant document.body.insertAdjacentHTML target creation and matching getElementById(...).remove() block from the comments-drawer, outline-drawer, and properties-drawer describes in NoteEditor.spec.ts. Retain their vi.clearAllMocks and other test-specific lifecycle code.

- [ ] **Step 4: Run the shared and drawer suites**

Run:

~~~powershell
.\scripts\jt.ps1 npm test -- src/test-setup.spec.ts src/NoteEditor.spec.ts
~~~

Expected: PASS, including drawer tests rendered through the real shared Teleport target.

- [ ] **Step 5: Commit the fixture lifecycle**

~~~powershell
git add frontend/src/test-setup.ts frontend/src/test-setup.spec.ts frontend/src/NoteEditor.spec.ts
git commit -m "test(frontend): provide shared drawer teleport target"
~~~

### Task 2: Complete the App API mock and prove notification initialization is quiet

**Files:**
- Modify: frontend/src/App.spec.ts

**Interfaces:**
- Consumes: App.vue calling getNotifications(activeWorkspaceId) during initWorkspace.
- Produces: a local getNotifications mock that resolves to an empty NotificationItem array.

- [ ] **Step 1: Add a failing App-initialization assertion**

Add getNotifications to the services/api import in App.spec.ts. Add this test in the App Component describe:

~~~ts
it('loads an empty notification list without reporting a mock error', async () => {
  const errorSpy = vi.spyOn(console, 'error').mockImplementation(() => {})
  const wrapper = mount(App)

  await flushPromises()

  expect(getNotifications).toHaveBeenCalledWith(1)
  expect(errorSpy).not.toHaveBeenCalledWith(
    'Failed to load notifications:',
    expect.anything(),
  )

  wrapper.unmount()
  errorSpy.mockRestore()
})
~~~

- [ ] **Step 2: Run the App test to verify it fails for the missing export**

Run:

~~~powershell
.\scripts\jt.ps1 npm test -- src/App.spec.ts
~~~

Expected: FAIL with the Vitest message that getNotifications is not defined on the services/api mock.

- [ ] **Step 3: Add the minimal mock implementation**

In the existing vi.mock('./services/api', ...) object in App.spec.ts, add:

~~~ts
getNotifications: vi.fn().mockResolvedValue([]),
~~~

Do not alter the App component or substitute a partial module mock.

- [ ] **Step 4: Run the App test to verify quiet initialization**

Run:

~~~powershell
.\scripts\jt.ps1 npm test -- src/App.spec.ts
~~~

Expected: PASS; getNotifications is called for workspace 1 and no notification-loading mock error is reported.

- [ ] **Step 5: Commit the complete App mock**

~~~powershell
git add frontend/src/App.spec.ts
git commit -m "test(frontend): mock notification loading"
~~~

### Task 3: Verify warning-free frontend execution and integrated regression coverage

**Files:**
- Modify: none

**Interfaces:**
- Consumes: Task 1 shared fixture and Task 2 complete App mock.
- Produces: evidence that the full Vitest suite has no #340 warning signatures.

- [ ] **Step 1: Run the entire frontend suite and inspect warning signatures**

Run:

~~~powershell
$testOutput = .\scripts\jt.ps1 npm test 2>&1
$testOutput
if ($testOutput -match 'Failed to locate Teleport target with selector "#app-right-drawer"|Invalid Teleport target on mount|No "getNotifications" export is defined') {
  throw 'Issue #340 warning signature remains in the frontend test output.'
}
~~~

Expected: command exits 0 and the output contains none of the three expressions.

- [ ] **Step 2: Run the integrated suite**

Run:

~~~powershell
.\scripts\jt.ps1 test
~~~

Expected: command exits 0. Existing unrelated dependency and bundle-size warnings are outside #340.

- [ ] **Step 3: Inspect the branch diff and commit the verification-only task if necessary**

Run:

~~~powershell
git diff --check main...HEAD
git status --short --branch
~~~

Expected: no whitespace errors and no uncommitted tracked changes. Do not create a commit for Task 3 unless a tracked verification artifact was intentionally added.

## Plan self-review

- **Spec coverage:** Task 1 creates one stable shared Teleport target and removes duplicate local lifecycle code. Task 2 supplies the exact App API export called during initialization. Task 3 verifies the specified warning strings are absent from the complete frontend suite and runs the integrated suite.
- **Placeholder scan:** Every file, command, expected outcome, warning signature, fixture id, function, and mock value is explicit.
- **Type consistency:** The setup id, getNotifications export, workspace id, and real App initialization path use the same names as the existing source.

