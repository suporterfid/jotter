# App Version Display Design

## Problem

There is no way to tell, from the running app itself, which build is currently live. After a deploy, verifying it landed requires SSHing into the host and diffing files or asset hashes by hand (as happened this session, investigating a "I don't see my changes" report — the deploy had in fact landed correctly, but there was no quick way to confirm that from the UI). A visible version indicator, refreshed automatically on every release build, removes that friction.

## Goals

- The frontend and backend each expose a build-identifying version string.
- The version is generated automatically as part of `./scripts/jt.sh release` — no manual number to remember to bump.
- The version is visible in the UI (Sidebar footer) without navigating anywhere.
- If frontend and backend versions ever diverge (a partial or out-of-sync deploy), that's visible, not silently hidden.

## Out of Scope

- Manual semantic versioning (`1.4.2`-style numbers). The user explicitly chose the auto-generated git-sha + timestamp approach over manual bumping.
- A dedicated "About" page or admin-only visibility — the version shows in the Sidebar footer for every user.
- Versioning of anything other than "the currently deployed release build" (no per-feature flags, no changelog).

## Architecture

**Version format:** `<short-git-sha> · <UTC build timestamp>`, e.g. `a073e7d · 2026-08-01 16:05`. Generated once, at release-build time, and baked identically into both the frontend bundle and the backend release directory — they come from the same build, so under normal deploys they match exactly.

**Generation (`scripts/jt.sh`):** `cmd_release()` computes `GIT_SHA=$(git rev-parse --short HEAD)` and `BUILD_TIME=$(date -u +'%Y-%m-%d %H:%M')` before invoking `docker compose --profile tools run --rm --build release`, and exports them so `compose.yaml`'s `release` service can pass them through as Docker build-args (compose supports `${VAR}` interpolation in `build.args`).

**Frontend (`docker/release/Dockerfile`, `frontend` stage):** the Dockerfile declares `ARG GIT_SHA` and `ARG BUILD_TIME` in the `frontend` stage, and sets `ENV VITE_APP_VERSION="${GIT_SHA} · ${BUILD_TIME}"` before `RUN npm run build`. Vite automatically exposes any `VITE_`-prefixed environment variable present at build time as `import.meta.env.VITE_APP_VERSION` — no `vite.config.ts` changes needed. A small `frontend/src/version.ts` module exports `export const APP_VERSION = import.meta.env.VITE_APP_VERSION || 'dev'` as the one place the rest of the frontend imports from (avoids `import.meta.env` string-literal accesses scattered around, and gives local `npm run dev` a sane `'dev'` fallback since that env var is never set outside the release build).

**Backend (`docker/release/Dockerfile`, `release` stage):** the same `ARG GIT_SHA`/`ARG BUILD_TIME` are declared in the `release` stage, and a plain `VERSION` file is written into the release directory: `RUN echo "${GIT_SHA} · ${BUILD_TIME}" > /release/app/VERSION`. `AuthController@config` (already the one endpoint the frontend calls once on boot) reads this file at request time — `File::exists(base_path('VERSION')) ? trim(File::get(base_path('VERSION'))) : null` — and adds it to its existing JSON response as a `version` key. No new endpoint, since `config()` already fires on every app boot and its response already carries app-wide settings the frontend needs before rendering.

**Frontend consumption:** `App.vue`'s existing boot-time fetch of `/api/auth/config` (used today for the SSO login URL / auth provider) also captures `data.version` into a `backendVersion` ref, and passes both `frontendVersion` (from `version.ts`, always available immediately, no fetch needed) and `backendVersion` (arrives async) down to `Sidebar.vue` as props.

**Display logic:** Sidebar footer shows just the frontend version (`v a073e7d · 2026-08-01 16:05`) in the common case. If `backendVersion` is present AND differs from `frontendVersion`, the footer additionally shows `(backend: <backendVersion>)` right after — surfacing a mismatch as a visible warning-style diagnostic rather than silently picking one. If `backendVersion` hasn't arrived yet (still loading) or is `null` (non-release environment), nothing extra is shown.

## Error Handling

- Local dev (`npm run dev`, `jt.sh up`, not a release build): `VITE_APP_VERSION` is unset → frontend shows `dev`. The `VERSION` file doesn't exist in a non-release container → backend's `version` is `null` → footer shows only `v dev`, no backend comparison.
- `/api/auth/config` request fails entirely (network error): `backendVersion` stays `null` (existing catch-based error handling in `App.vue` already covers this call) — footer just shows the frontend version, same as the "not yet arrived" case. No new error state needed.

## Testing

- Backend: a feature test on `GET /api/auth/config` asserting `version` is present and matches the `VERSION` file's contents when the file exists, and is `null` when it doesn't (using a temp file / `Storage::fake`-style setup, or simply asserting against `base_path('VERSION')`'s real absence in the test environment, whichever is simpler to write against the actual controller code once it exists).
- Frontend: a `version.ts` module is trivial (a one-line constant), no dedicated unit test needed beyond what `Sidebar.spec.ts` covers. `Sidebar.spec.ts` gets new cases: renders the frontend version in the footer; shows the backend version alongside when it differs from the frontend version; does not show a backend version segment when they match or when backend version is absent.
