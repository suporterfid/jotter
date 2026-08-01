# App Version Display Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Generate a build-identifying version string (`<short-git-sha> · <UTC build timestamp>`) automatically on every `./scripts/jt.sh release`, bake it into both the frontend bundle and the backend release directory, and show it in the Sidebar footer — with a visible warning if frontend and backend versions ever diverge.

**Architecture:** `jt.sh release` computes `GIT_SHA`/`BUILD_TIME` and passes them as Docker build-args into `docker/release/Dockerfile`. The frontend stage sets them as a `VITE_APP_VERSION` env var before `npm run build` (Vite auto-exposes any `VITE_`-prefixed env var as `import.meta.env.VITE_APP_VERSION`, no vite.config.ts change needed). The release stage writes a plain `VERSION` file into the release directory. `AuthController@config` reads that file and returns it as `version` in its existing JSON response. `App.vue` calls `getAuthConfig()` once on mount (a new call there — today only `LoginModal.vue` calls it, which doesn't fire for an already-authenticated user, so version needs its own always-fires call) and passes both versions down to `Sidebar.vue`.

**Tech Stack:** Bash (`jt.sh`), Docker multi-stage build, Laravel 11 (PHP 8.2+/8.3), PHPUnit, Vue 3 `<script setup>` + TypeScript, Vite, Vitest.

## Global Constraints

- Use only `./scripts/jt.sh` wrappers for all dependency/build/test/db commands — never call `docker`/`php`/`npm` directly.
- No manual semver bumping anywhere — the version must be fully automatic from `jt.sh release`, with zero manual steps.
- The version format is exactly `<short-git-sha> · <UTC build timestamp formatted as 'YYYY-MM-DD HH:MM'>` on both frontend and backend — same format, same source values, so they match under normal deploys.
- Local dev (`jt.sh up`, `npm run dev`) is not a release build: frontend must fall back to `'dev'`, backend's `version` must be `null` when no `VERSION` file exists. Do not treat this as an error.

---

### Task 1: Generate and thread the version through the release Docker build

**Files:**
- Modify: `scripts/jt.sh` (`cmd_release()`)
- Modify: `compose.yaml` (the `release` service)
- Modify: `docker/release/Dockerfile` (`frontend` and `release` stages)

**Interfaces:**
- Produces: inside the built release image, `frontend/public/build`'s JS bundle has `import.meta.env.VITE_APP_VERSION` baked in as `"<sha> · <timestamp>"`, and `/release/app/VERSION` is a one-line text file with the same string. Task 2 (frontend `version.ts` module) and Task 3 (backend `AuthController@config`) consume these directly — no other interface changes.

- [ ] **Step 1: Update `cmd_release()` in `scripts/jt.sh`**

Replace:

```bash
cmd_release() {
  ensure_env
  mkdir -p dist
  compose --profile tools run --rm --build release
  test -s dist/jotter-release.zip
  test -s dist/jotter-release.zip.sha256
  (cd dist && sha256sum -c jotter-release.zip.sha256)
  echo "Release written to dist/jotter-release.zip"
}
```

With:

```bash
cmd_release() {
  ensure_env
  mkdir -p dist
  export GIT_SHA="$(git rev-parse --short HEAD)"
  export BUILD_TIME="$(date -u +'%Y-%m-%d %H:%M')"
  compose --profile tools run --rm --build release
  test -s dist/jotter-release.zip
  test -s dist/jotter-release.zip.sha256
  (cd dist && sha256sum -c jotter-release.zip.sha256)
  echo "Release written to dist/jotter-release.zip (version: ${GIT_SHA} · ${BUILD_TIME})"
}
```

`export` makes `GIT_SHA`/`BUILD_TIME` visible to the `docker compose` subprocess so `compose.yaml`'s `${GIT_SHA}`/`${BUILD_TIME}` interpolation (next step) can pick them up.

- [ ] **Step 2: Pass them as build-args in `compose.yaml`**

Find the `release` service (currently):

```yaml
  release:
    profiles:
      - tools
    build:
      context: .
      dockerfile: docker/release/Dockerfile
      target: release
    volumes:
      - ./dist:/dist
    command:
      - sh
      - -c
      - cp /jotter-release.zip /jotter-release.zip.sha256 /dist/
```

Add a `build.args` block:

```yaml
  release:
    profiles:
      - tools
    build:
      context: .
      dockerfile: docker/release/Dockerfile
      target: release
      args:
        GIT_SHA: ${GIT_SHA:-dev}
        BUILD_TIME: ${BUILD_TIME:-unknown}
    volumes:
      - ./dist:/dist
    command:
      - sh
      - -c
      - cp /jotter-release.zip /jotter-release.zip.sha256 /dist/
```

The `:-dev`/`:-unknown` defaults mean `docker compose build release` (or any invocation outside `jt.sh release`, e.g. manual testing) still produces a valid, if generic, version rather than an empty string.

- [ ] **Step 3: Declare and use the ARGs in `docker/release/Dockerfile`**

In the `frontend` stage, currently:

```dockerfile
FROM node:22-bookworm-slim AS frontend

WORKDIR /app/frontend

COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci

COPY frontend/ ./
RUN npm run build
```

Change to:

```dockerfile
FROM node:22-bookworm-slim AS frontend

ARG GIT_SHA=dev
ARG BUILD_TIME=unknown

WORKDIR /app/frontend

COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci

COPY frontend/ ./
ENV VITE_APP_VERSION="${GIT_SHA} · ${BUILD_TIME}"
RUN npm run build
```

In the `release` stage, currently starts with:

```dockerfile
FROM alpine:3.21 AS release

RUN apk add --no-cache zip

WORKDIR /release

COPY --from=vendor /app /release/app
COPY --from=frontend /app/public/build /release/app/public/build

RUN cd /release/app \
    && rm -rf \
```

Change to:

```dockerfile
FROM alpine:3.21 AS release

ARG GIT_SHA=dev
ARG BUILD_TIME=unknown

RUN apk add --no-cache zip

WORKDIR /release

COPY --from=vendor /app /release/app
COPY --from=frontend /app/public/build /release/app/public/build

RUN echo "${GIT_SHA} · ${BUILD_TIME}" > /release/app/VERSION

RUN cd /release/app \
    && rm -rf \
```

(The `RUN echo ... > VERSION` line is inserted as its own step, right after both `COPY --from=` lines and before the existing `rm -rf` cleanup step — the cleanup step doesn't touch `VERSION` so ordering relative to it doesn't matter, but placing it before keeps all "what's in the final app dir" steps grouped together.)

- [ ] **Step 4: Verify the build produces a real version**

Run: `./scripts/jt.sh release`
Expected: succeeds as before, and the final echoed line shows a real short SHA and a real timestamp, e.g. `Release written to dist/jotter-release.zip (version: 9420233 · 2026-08-01 16:30)` — not `dev`/`unknown`.

Then verify the artifacts directly:

```bash
mkdir -p /tmp/release-check && cd /tmp/release-check
unzip -o -q /home/ubuntu/projects/web/iroh/jotter/.claude/worktrees/app-version/dist/jotter-release.zip
cat app/VERSION
grep -o 'VITE_APP_VERSION[^"]*' app/public/build/assets/index-*.js || true
```

Expected: `app/VERSION` contains the real sha+timestamp string. (The grep for `VITE_APP_VERSION` inside the built JS may not match literally since Vite substitutes the value at build time and doesn't keep the variable name in the output — Task 2's own test is the real verification for the frontend side. This check is just a sanity confirmation that the release zip was rebuilt.)

- [ ] **Step 5: Commit**

```bash
git add scripts/jt.sh compose.yaml docker/release/Dockerfile
git commit -m "feat: bake git sha + build timestamp into release builds"
```

---

### Task 2: Frontend version module and Sidebar footer display

**Files:**
- Create: `frontend/src/version.ts`
- Modify: `frontend/src/components/Sidebar.vue`
- Modify: `frontend/src/Sidebar.spec.ts`

**Interfaces:**
- Consumes: `import.meta.env.VITE_APP_VERSION` (set by Task 1's Docker build; unset in local dev).
- Produces: `export const APP_VERSION: string` from `version.ts`. New `Sidebar.vue` props `frontendVersion: string` (required) and `backendVersion?: string | null` (optional). Task 3/4 (App.vue wiring) consumes these prop names directly.

- [ ] **Step 1: Write the failing test for `version.ts`'s consumption in Sidebar**

Add to `frontend/src/Sidebar.spec.ts` (a new `describe` block; this file has no shared base-props object — every test inlines its own `props`, so follow that same pattern):

```typescript
describe('Sidebar version footer', () => {
  it('renders the frontend version', () => {
    const wrapper = mount(Sidebar, {
      props: { notes: [], selectedNoteId: null, workspaceId: 1, folderPositions: [], workspaces: [], frontendVersion: 'abc1234 · 2026-08-01 16:30' },
    })
    expect(wrapper.find('[data-testid="app-version"]').text()).toContain('abc1234 · 2026-08-01 16:30')
  })

  it('shows the backend version alongside when it differs from the frontend version', () => {
    const wrapper = mount(Sidebar, {
      props: {
        notes: [], selectedNoteId: null, workspaceId: 1, folderPositions: [], workspaces: [],
        frontendVersion: 'abc1234 · 2026-08-01 16:30',
        backendVersion: 'def5678 · 2026-08-01 16:31',
      },
    })
    expect(wrapper.find('[data-testid="app-version-mismatch"]').text()).toContain('def5678 · 2026-08-01 16:31')
  })

  it('does not show a mismatch segment when backend version matches frontend version', () => {
    const wrapper = mount(Sidebar, {
      props: {
        notes: [], selectedNoteId: null, workspaceId: 1, folderPositions: [], workspaces: [],
        frontendVersion: 'abc1234 · 2026-08-01 16:30',
        backendVersion: 'abc1234 · 2026-08-01 16:30',
      },
    })
    expect(wrapper.find('[data-testid="app-version-mismatch"]').exists()).toBe(false)
  })

  it('does not show a mismatch segment when backend version is absent', () => {
    const wrapper = mount(Sidebar, {
      props: { notes: [], selectedNoteId: null, workspaceId: 1, folderPositions: [], workspaces: [], frontendVersion: 'abc1234 · 2026-08-01 16:30' },
    })
    expect(wrapper.find('[data-testid="app-version-mismatch"]').exists()).toBe(false)
  })
})
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./scripts/jt.sh npm -- run test -- Sidebar.spec.ts`
Expected: FAIL — `frontendVersion` is not a known prop (TS error) and `[data-testid="app-version"]` doesn't exist yet.

- [ ] **Step 3: Create `frontend/src/version.ts`**

```typescript
export const APP_VERSION: string = import.meta.env.VITE_APP_VERSION || 'dev'
```

- [ ] **Step 4: Add the props and template to `Sidebar.vue`**

Add to the `defineProps` block (the same one Task 4/5 of the previous workspace-switcher plan already extended with `workspaces`):

```typescript
  frontendVersion: string
  backendVersion?: string | null
```

In the template, right after the closing `</div>` of the existing `<!-- User Profile Footer -->` block (after line 376, before the closing `</aside>`):

```vue
    <div class="sidebar-version" data-testid="app-version">
      v {{ props.frontendVersion }}
      <span
        v-if="props.backendVersion && props.backendVersion !== props.frontendVersion"
        class="sidebar-version-mismatch"
        data-testid="app-version-mismatch"
      >
        (backend: {{ props.backendVersion }})
      </span>
    </div>
```

Add matching CSS near the existing `.sidebar-footer` rules (around line 1250):

```css
.sidebar-version {
  padding: var(--space-1) var(--space-4) var(--space-2);
  font-size: 0.6875rem;
  color: var(--color-text-muted);
  text-align: center;
}

.sidebar-version-mismatch {
  color: var(--color-status-warning);
}
```

(`--color-status-warning` is already used elsewhere in this codebase, e.g. `OutgoingLinksPanel.vue`'s `.unresolved-badge` — reuse it rather than inventing a new token.)

- [ ] **Step 5: Run test to verify it passes**

Run: `./scripts/jt.sh npm -- run test -- Sidebar.spec.ts`
Expected: PASS, including the four new tests and all pre-existing ones (existing `mount(Sidebar, ...)` calls in this file that don't pass `frontendVersion` will now fail TypeScript compilation once it's required — fix every pre-existing test in this file the same way Task 4 of the workspace-switcher plan fixed the `workspaces` prop: add `frontendVersion: 'dev'` to each one's `props` object).

- [ ] **Step 6: Fix the other two files that mount `Sidebar` directly**

This repeats the exact class of gap the workspace-switcher feature hit twice (`PanelHeader`'s `collapsed` prop, then `Sidebar`'s `workspaces` prop): making `frontendVersion` required breaks `vue-tsc -b` in any other file mounting `Sidebar` without it. There are two: `frontend/src/SidebarNotifications.spec.ts` (its `baseProps` object) and `frontend/src/a11y.spec.ts` (its two inline `mount(Sidebar, ...)` calls). Add `frontendVersion: 'dev'` to all of them.

- [ ] **Step 7: Run the full frontend suite to confirm no regressions**

Run: `./scripts/jt.sh npm -- run test`
Expected: all tests pass, including `SidebarNotifications.spec.ts` and `a11y.spec.ts`.

- [ ] **Step 8: Commit**

```bash
git add frontend/src/version.ts frontend/src/components/Sidebar.vue frontend/src/Sidebar.spec.ts frontend/src/SidebarNotifications.spec.ts frontend/src/a11y.spec.ts
git commit -m "feat: add version.ts and Sidebar version footer"
```

---

### Task 3: Backend `version` field on `/api/auth/config`

**Files:**
- Modify: `app/Http/Controllers/AuthController.php`
- Modify: `tests/Feature/AuthTest.php`

**Interfaces:**
- Produces: `GET /api/auth/config` response gains a `version` key: `{"data": {"provider": ..., "sso_login_url": ..., "version": "<sha> · <timestamp>" | null}}`. Task 4 (frontend `getAuthConfig()` type + App.vue) consumes this key directly.

- [ ] **Step 1: Write the failing feature tests**

Add to `tests/Feature/AuthTest.php`, near the existing `test_auth_config_endpoint_*` tests:

```php
public function test_auth_config_endpoint_reports_version_from_version_file(): void
{
    $versionFile = base_path('VERSION');
    file_put_contents($versionFile, "abc1234 · 2026-08-01 16:30\n");

    try {
        $this->getJson('/api/auth/config')
            ->assertOk()
            ->assertJsonPath('data.version', 'abc1234 · 2026-08-01 16:30');
    } finally {
        unlink($versionFile);
    }
}

public function test_auth_config_endpoint_reports_null_version_when_version_file_absent(): void
{
    $versionFile = base_path('VERSION');
    if (file_exists($versionFile)) {
        unlink($versionFile);
    }

    $this->getJson('/api/auth/config')
        ->assertOk()
        ->assertJsonPath('data.version', null);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./scripts/jt.sh test -- --filter=test_auth_config_endpoint_reports`
Expected: FAIL — `data.version` key doesn't exist in the response yet.

- [ ] **Step 3: Update `AuthController@config`**

Current:

```php
    public function config(): JsonResponse
    {
        $provider = (string) config('jotter.auth_provider', 'local');

        $ssoLoginUrl = null;
        if ($provider === 'grandpasson') {
            $brokerBaseUrl = config('jotter.sso.broker_base_url');
            $clientId = config('jotter.sso.client_id');

            if ($brokerBaseUrl && $clientId) {
                $ssoLoginUrl = rtrim((string) $brokerBaseUrl, '/').'/login/email?'.http_build_query([
                    'client_id' => $clientId,
                    'redirect_uri' => config('app.url'),
                    'state' => bin2hex(random_bytes(16)),
                ]);
            }
        }

        return response()->json([
            'data' => [
                'provider' => $provider,
                'sso_login_url' => $ssoLoginUrl,
            ],
        ]);
    }
```

Change to:

```php
    public function config(): JsonResponse
    {
        $provider = (string) config('jotter.auth_provider', 'local');

        $ssoLoginUrl = null;
        if ($provider === 'grandpasson') {
            $brokerBaseUrl = config('jotter.sso.broker_base_url');
            $clientId = config('jotter.sso.client_id');

            if ($brokerBaseUrl && $clientId) {
                $ssoLoginUrl = rtrim((string) $brokerBaseUrl, '/').'/login/email?'.http_build_query([
                    'client_id' => $clientId,
                    'redirect_uri' => config('app.url'),
                    'state' => bin2hex(random_bytes(16)),
                ]);
            }
        }

        $versionFile = base_path('VERSION');
        $version = file_exists($versionFile) ? trim(file_get_contents($versionFile)) : null;

        return response()->json([
            'data' => [
                'provider' => $provider,
                'sso_login_url' => $ssoLoginUrl,
                'version' => $version,
            ],
        ]);
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./scripts/jt.sh test -- --filter=test_auth_config_endpoint`
Expected: PASS (all 5 `test_auth_config_endpoint_*` tests — the 3 pre-existing plus the 2 new ones).

- [ ] **Step 5: Run the full PHP suite to check for regressions**

Run: `./scripts/jt.sh test`
Expected: all PHP tests pass (there is no `VERSION` file in the test/dev environment by default, so every other test hitting `/api/auth/config` — e.g. the 3 pre-existing ones — must still pass with `version: null` implicitly satisfied by `assertJson` only checking the keys it names, not asserting the full response shape... verify this holds; if any pre-existing test uses `assertExactJson` or similar strict-shape assertion instead of `assertJson`/`assertJsonPath`, it will need `'version' => null` added to its expected array).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/AuthController.php tests/Feature/AuthTest.php
git commit -m "feat: expose release version on /api/auth/config"
```

---

### Task 4: Wire both versions into `App.vue`

**Files:**
- Modify: `frontend/src/services/api.ts`
- Modify: `frontend/src/App.vue`
- Modify: `frontend/src/App.spec.ts`

**Interfaces:**
- Consumes: `version.ts`'s `APP_VERSION` (Task 2), `AuthController@config`'s new `version` field (Task 3), `Sidebar.vue`'s new `frontendVersion`/`backendVersion` props (Task 2).
- Produces: nothing new consumed elsewhere — this is the final integration point.

- [ ] **Step 1: Add `version` to the `AuthConfig` type**

In `frontend/src/services/api.ts`, current:

```typescript
export interface AuthConfig {
  provider: 'local' | 'grandpasson'
  sso_login_url: string | null
}
```

Change to:

```typescript
export interface AuthConfig {
  provider: 'local' | 'grandpasson'
  sso_login_url: string | null
  version: string | null
}
```

- [ ] **Step 2: Write the failing test in `App.spec.ts`**

Check the top of `frontend/src/App.spec.ts` for its existing `vi.mock('./services/api', ...)` block and add a `getAuthConfig` mock alongside the others already there if one isn't present yet (the file may already import/mock it for other reasons — check first, don't assume). Add:

```typescript
it('passes the frontend version and fetched backend version down to Sidebar', async () => {
  vi.mocked(getAuthConfig).mockResolvedValueOnce({
    provider: 'local',
    sso_login_url: null,
    version: 'abc1234 · 2026-08-01 16:30',
  })

  const wrapper = mount(App)
  await flushPromises()

  expect(wrapper.findComponent({ name: 'Sidebar' }).props('backendVersion')).toBe('abc1234 · 2026-08-01 16:30')
  expect(wrapper.findComponent({ name: 'Sidebar' }).props('frontendVersion')).toBe('dev')
})
```

(`frontendVersion` is expected to be `'dev'` here because `import.meta.env.VITE_APP_VERSION` is unset in the Vitest environment, exactly like local dev — this is the correct, expected fallback per the Global Constraints, not a test artifact to work around.)

- [ ] **Step 3: Run test to verify it fails**

Run: `./scripts/jt.sh npm -- run test -- App.spec.ts`
Expected: FAIL — `Sidebar` isn't receiving `frontendVersion`/`backendVersion` props yet.

- [ ] **Step 4: Wire it into `App.vue`**

Add to the import list from `./services/api` (the existing multi-line `import { ... } from './services/api'` block):

```typescript
  getAuthConfig,
```

Add a new ref near the other top-level refs (e.g. near `currentUser`):

```typescript
import { APP_VERSION } from './version'

const backendVersion = ref<string | null>(null)
```

In `onMounted`, add a call to fetch it (it's fine for this to run independently of the `getMe()`/`initWorkspace()` flow — it's a background enrichment, not blocking):

```typescript
onMounted(async () => {
  setUnauthenticatedHandler(() => {
    showLoginModal.value = true
  })

  try {
    const user = await getMe()
    currentUser.value = user
  } catch {
    showLoginModal.value = true
  }

  getAuthConfig()
    .then((config) => { backendVersion.value = config.version })
    .catch(() => {})

  await initWorkspace()
})
```

Add the two new props to the `<Sidebar>` invocation:

```vue
      :frontend-version="APP_VERSION"
      :backend-version="backendVersion"
```

(placed alongside the existing `:workspaces="workspaces"` prop, anywhere in the existing prop list — order doesn't matter for Vue template props.)

- [ ] **Step 5: Run test to verify it passes**

Run: `./scripts/jt.sh npm -- run test -- App.spec.ts`
Expected: PASS, including the new test and all pre-existing ones.

- [ ] **Step 6: Run the full frontend suite, then the full `jt.sh test` (PHP + build + frontend)**

Run: `./scripts/jt.sh npm -- run test`
Expected: all pass.

Run: `./scripts/jt.sh test`
Expected: PHP suite passes, `vue-tsc -b && vite build` succeeds (confirms `frontendVersion`/`backendVersion` prop types line up across every file that mounts `Sidebar`), vitest suite passes.

- [ ] **Step 7: Commit**

```bash
git add frontend/src/services/api.ts frontend/src/App.vue frontend/src/App.spec.ts
git commit -m "feat: fetch and display backend version in App.vue"
```

---

### Task 5: Full-suite verification

**Files:** none (verification only)

- [ ] **Step 1: Run the complete test suite (PHP + frontend build + vitest)**

Run: `./scripts/jt.sh test`
Expected: exit 0, all PHP tests pass, `vue-tsc -b && vite build` succeeds with no type errors, all vitest tests pass.

- [ ] **Step 2: Run a real release build and manually confirm the version end-to-end**

Run: `./scripts/jt.sh release`
Expected: succeeds, echoes a real (non-`dev`/`unknown`) version string. Optionally spin up `./scripts/jt.sh up` and check the Sidebar footer in a browser shows the same version, with no mismatch warning (frontend and backend built together, so they should match).

- [ ] **Step 3: If green, proceed to `superpowers:finishing-a-development-branch`**

Push the branch, open a PR, and follow the established auto-merge-on-green-CI pattern from prior features in this project.
