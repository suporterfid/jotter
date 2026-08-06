# Jotter i18n (PT-BR/EN) design

## Context

Second sub-project in the cross-repo i18n effort (see GrandpaSSOn's `docs/superpowers/specs/2026-08-06-grandpasson-locale-foundation-design.md`, PR [#120](https://github.com/suporterfid/grandpasson/pull/120)). GrandpaSSOn now owns a single `locale` (pt-BR default, en) per user, exposed via `GET/POST /me/locale` and the `/session/exchange` claim.

Jotter does **not** call `/session/exchange`. It reads GrandpaSSOn's `sso_users`/`sso_sessions` tables directly with a raw PDO connection every request (`GrandpaSSOnIdentityProvider::resolveFromGrandpaSsonSession()`, `app/Domain/Auth/Providers/GrandpaSSOnIdentityProvider.php:101`), because GrandpaSSOn and jotter share one MySQL database distinguished only by table prefix. This means jotter already gets GrandpaSSOn's locale live, on every request, with no propagation delay — no caching problem to solve for the read side. This spec covers only jotter: PT-BR/EN across UI, Laravel validation/error messages, and the (currently nonexistent) email pattern. Grandpasson, taskconnect, tallymark, and statusconnect are separate specs.

## Decisions locked in during brainstorming

- Locale write path goes through GrandpaSSOn's `POST /me/locale` HTTP endpoint (not a direct SQL write to `sso_users`) — reuses its CSRF/rate-limit/audit-log machinery instead of duplicating it.
- Jotter caches `locale` on its own local `users.locale` column, synced on every login, so background jobs/emails (no live SSO session/cookie available) still know the right language.
- Full scope in one pass: all 37 Vue components get converted, not just the highest-traffic ones.
- Nested-by-feature vue-i18n message keys (`admin.workspaces.title`, not a flat `title`) to avoid collisions across 37 screens.
- Language switcher lives as a header/sidebar dropdown next to the existing theme toggle — no new settings page.

## 1. Locale read path (already live, needs wiring)

`GrandpaSSOnIdentityProvider::resolveFromGrandpaSsonSession()` adds `locale` to its `SELECT * FROM {$usersTable}` (already `SELECT *`, so the column is already present once GrandpaSSOn's migration lands — no query change needed, only reading `$ssoUser->locale ?? 'pt-BR'`).

Today this method calls `User::query()->firstOrCreate(['email' => ...], [...])`, which only sets defaults on the very first login and never updates an existing row on later logins — display_name, is_admin, etc. all already tolerate this staleness. `locale` cannot tolerate it the same way, because background jobs read the locally cached value with no live SSO context. Switch to `updateOrCreate`:

```php
$user = User::query()->updateOrCreate(
    ['email' => $ssoUser->primary_email],
    [
        'name' => $ssoUser->display_name ?? 'SSO User',
        'locale' => $ssoUser->locale ?? 'pt-BR',
        // password/is_admin stay create-only defaults via a separate array merge,
        // see implementation plan for the exact split.
    ],
);
```

`AuthenticatedSubject` (`app/Domain/Auth/AuthenticatedSubject.php`) gains a `public readonly string $locale = 'pt-BR'` constructor param. Every place that constructs one passes it explicitly:

- `GrandpaSSOnIdentityProvider::resolveFromGrandpaSsonSession()` → `$ssoUser->locale ?? 'pt-BR'`
- `GrandpaSSOnIdentityProvider::resolveFromServiceToken()` (machine caller, no human) → default `'pt-BR'`, unused
- `LocalIdentityProvider::resolveIdentity()`, both the MachineToken branch and the session branch → `$user->locale`
- `LocalIdentityProvider::authenticate()` (delegates to the above)

New middleware `App\Http\Middleware\SetLocaleFromSubject`: resolves the subject the same way `AuthController::me()` does and calls `App::setLocale($subject->locale)` before the route handler runs, so every `__()` call, validation message, and JSON error response in that request is already in the right language. Registered globally in `bootstrap/app.php`'s middleware stack, after session/cookie middleware but before route handling.

`AuthController::me()` adds `'locale' => $subject->locale` to its JSON payload. Frontend's `AuthUser` TypeScript interface (`frontend/src/services/types.ts:90`) gains `locale: string`.

## 2. Locale write path

New route `POST /api/user/locale`, new `UserLocaleController::update()`:

- Validates `locale` is `pt-BR` or `en` (400 otherwise) — reuse GrandpaSSOn's `Locale::SUPPORTED` list conceptually; jotter doesn't share code with GrandpaSSOn's PHP codebase, so this is a small local constant, not an import.
- Resolves the current `AuthenticatedSubject`. Branches on whether the subject came from GrandpaSSOn SSO (`attributes['sso_provider'] === 'grandpasson'`) or is local:
  - **SSO subject:** proxies to GrandpaSSOn using Laravel's HTTP client, forwarding the `AUTHSESSID` cookie from the incoming request:
    1. `GET {broker_base_url}/me/locale` with the cookie, to obtain a fresh CSRF token (GrandpaSSOn's CSRF token is tied to its own PHP session, not jotter's Laravel session — jotter cannot mint one itself).
    2. `POST {broker_base_url}/me/locale` with the same cookie, `csrf` from step 1, and the new `locale`.
    3. On a non-200 from either call, return the failure to the client (502-style passthrough) without touching jotter's local `users.locale` — never let the two values diverge.
  - **Local subject:** no GrandpaSSOn to sync — just updates `users.locale` directly.
  - **Both branches**, on success: `$subject->user->update(['locale' => $locale])` (write-through cache for jobs/emails), then respond `{ok: true, locale: $locale}`.

The proxy calls' base URL reuses `config('jotter.sso.broker_base_url')` (backed by `JOTTER_SSO_BROKER_BASE_URL`, `config/jotter.php:41`) — the same config key GrandpaSSOn integration already uses elsewhere.

## 3. Backend Laravel i18n (validation + error messages)

`lang/en/` and `lang/pt-BR/` directories: publish Laravel's default validation strings (`php artisan lang:publish`) into both, translating the pt-BR copy. A new `lang/en/messages.php` / `lang/pt-BR/messages.php` pair holds jotter's own hardcoded response strings — e.g. `AdminUserController.php`'s `'User deactivated successfully. Active sessions invalidated.'`, `AuthController.php`'s `'Invalid email or password.'` — replaced with `__('messages.user_deactivated')` etc. across the ~25 files currently inlining these. `SetLocaleFromSubject` (section 1) means no controller needs to think about locale itself — `__()` and Laravel's validator already resolve it from `App::getLocale()`.

## 4. Frontend (vue-i18n)

- `frontend/src/i18n/locales/en.ts` and `frontend/src/i18n/locales/pt-BR.ts`: nested-by-feature message objects (`nav`, `editor`, `admin.workspaces`, `admin.users`, `boards`, …) covering every hardcoded string across the 37 Vue components.
- `frontend/src/i18n/index.ts`: `createI18n({ legacy: false, locale: 'pt-BR', fallbackLocale: 'en', messages: { en, 'pt-BR': ptBR } })`. Registered in `main.ts` via `app.use(i18n)`, before mount.
- Boot sequence: the existing `/auth/me` fetch (wherever the app currently bootstraps `currentUser`) also sets `i18n.global.locale.value = data.locale` — the very first render already renders in the user's language, not just after a manual toggle.
- `composables/useLocale.ts`, mirroring `composables/useTheme.ts`'s shape: exposes the current locale (backed by vue-i18n's global `locale` ref, not a separate localStorage key — locale is server-owned, unlike theme) and `setLocale(newLocale)`, which updates `i18n.global.locale.value` immediately (instant UI feedback) and fires `POST /api/user/locale` in the background. A failed POST logs a warning but does not revert the already-applied UI change — worst case, the next login re-syncs from GrandpaSSOn's stored value.
- `LocaleToggle.vue`, mirroring `ThemeToggle.vue`'s structure (scoped styles, `min-width/height: 44px` tap target, `aria-label`): a two-state PT/EN toggle. Wired into `Sidebar.vue`'s `.sidebar-footer-actions` div (`frontend/src/components/Sidebar.vue:412`), next to the existing `<ThemeToggle />`.
- All 37 Vue components under `frontend/src/`: hardcoded string literals replaced with `t('namespace.key')` (script) / `{{ t('namespace.key') }}` (template) calls, keys populated into both locale files.

## 5. Email pattern (no code yet)

`app/Mail/` does not exist in jotter today — there are zero Mailable classes to convert. This section documents the pattern for whoever adds the first one, not a task to execute now (YAGNI — no dead code for a feature that doesn't exist): a Mailable's `build()` should call `Mail::locale($this->user->locale)` (or equivalent per-mailable locale scoping) before returning, so the queued job renders in the recipient's cached `users.locale`, not the request-time locale of whoever triggered the send.

## Explicitly out of scope

- taskconnect, tallymark, statusconnect — separate specs.
- Any change to GrandpaSSOn itself (already shipped in PR #120).
- A dedicated settings/profile page — the header dropdown covers the only preference that exists today.
- Real-time cross-tab sync — matches GrandpaSSOn foundation's "next login/refresh" decision; jotter's read path already gets it live every request regardless, so this only affects the cached `users.locale` used by background jobs.
