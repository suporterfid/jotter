# Jotter Canonical Visual Identity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Apply the canonical content-first identity to Jotter's authenticated SPA and generated public publishing/share pages without changing product behavior or Jotter-owned brand assets.

**Architecture:** Keep `frontend/src/styles/tokens.css` as the SPA source of truth and give the standalone static publisher an equivalent self-contained token contract. Resolve the persisted `light | dark | system` preference before first paint in every document; components consume semantic tokens, while PHP only exports static assets and localized static markup.

**Tech Stack:** Laravel 12, PHP 8.2+, Vue 3, TypeScript, vue-i18n, @vueuse/core, Vite, Vitest, Playwright, Docker Compose V2.

## Global Constraints

- Run PHP, Composer, Node, npm, MySQL, tests, builds, and release only through `scripts/jt.ps1` or `scripts/jt.sh`; never invoke toolchains directly on the host.
- Do not change note storage, Markdown rendering semantics, publish authorization, API contracts, editor commands, or Jotter brand assets under `assets/brand/`.
- Do not add Notion trademarks, proprietary assets, copy, fonts, logos, illustrations, icons, screenshots, or CDN font requests.
- Components and Blade public styles consume semantic custom properties only; no raw color values outside token construction blocks and documented test fixtures.
- The persisted preference key is `jotter-theme`; allowed stored values are exactly `light`, `dark`, and `system`. An absent preference resolves as `system` and is not written.
- The exact canonical semantic matrix is mandatory: light/dark canvas `#FFFFFF`/`#191919`; surface `#F7F6F3`/`#202020`; elevated `#FFFFFF`/`#252525`; hover `#EFEDEA`/`#2C2C2C`; selected `#E7F0FA`/`#123B60`; primary text `#252525`/`#F1F1EF`; secondary text `#5F5F5F`/`#C6C6C2`; disabled text `#929292`/`#888884`; inverse `#FFFFFF`/`#191919`; link `#0F5EAB`/`#79B8E8`; default border `#D9D7D3`/`#4A4A4A`; strong border `#8A8882`/`#6E6E6E`; primary action `#1A6DC1`/`#529CCA`; primary hover `#14599E`/`#70B4DE`; primary active `#104B86`/`#3E83B5`; action content `#FFFFFF`/`#111111`; subtle action `#E7F0FA`/`#173755`; focus `#1A6DC1`/`#79B8E8`; success fg/bg/border `#126B3A/#F1FAF4/#7CCB98` and `#7CDA9A/#13291C/#34794C`; warning `#7A4A00/#FFF7E6/#F0B35A` and `#F5C775/#33250D/#8D6418`; danger `#B42318/#FFF1F0/#F29A93` and `#F4A49E/#381B1B/#8E4540`; info `#0F5EAB/#EDF5FE/#85BCEB` and `#9DCCF2/#102B45/#3D78AA`.
- Use Inter UI, Source Serif 4 editorial, IBM Plex Mono code, and Noto script fallbacks; weights are 400, 500, 600, 700. Include self-hosted WOFF2 fonts and their licenses in release output.
- Use logical CSS properties, 44x44 CSS px interactive targets, a 720px prose measure, data views up to 1200px, breakpoints at 480/768/1024/1280px, visible focus, reduced motion, forced-colors support, 200% text zoom, and 320px/400% reflow.
- New user-visible copy is a message key in both `frontend/src/i18n/locales/en.ts` and `pt-BR.ts`; test `en-XA`, `ar-XB`, bidi values, CJK wrapping, and IME composition structurally without adding product translations.

---

### Task 1: Establish canonical tokens, type assets, and token governance

**Files:**
- Modify: `frontend/src/styles/tokens.css`
- Modify: `frontend/src/styles/fonts.css`
- Modify: `frontend/src/style.css`
- Create: `frontend/src/styles/tokens.spec.ts`
- Modify: `scripts/check-design-tokens.sh`
- Modify: `docs/visual-identity.md`
- Add licensed assets: `frontend/src/assets/fonts/source-serif-4-*.woff2`, `frontend/src/assets/fonts/ibm-plex-mono-*.woff2`, and accompanying OFL license files

**Interfaces:**
- Consumes: canonical values in `C:\workspace-offline\iroh\notion-inspired-visual-identity-spec.md` section 3 and the approved design doc.
- Produces: `--color-bg-*`, `--color-text-*`, `--color-border-*`, `--color-action-primary-*`, `--color-focus-ring`, `--color-{success,warning,danger,info}-{fg,bg,border}`, `--font-ui`, `--font-editorial`, `--font-code`, `--shadow-{0,1,2,3}`, and logical safe-area tokens consumed by all later tasks.

- [ ] **Step 1: Write failing token-contract tests.**

```ts
import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'

const css = readFileSync(new URL('./tokens.css', import.meta.url), 'utf8')
for (const token of ['--color-bg-canvas', '--color-action-primary', '--color-focus-ring', '--color-danger-border']) {
  it(`${token} exists in both themes`, () => {
    expect(css.match(new RegExp(`:root\\[data-theme="light"\\][\\s\\S]*?${token}`))).toBeTruthy()
    expect(css.match(new RegExp(`:root\\[data-theme="dark"\\][\\s\\S]*?${token}`))).toBeTruthy()
  })
}

it('uses the canonical light action blue', () => {
  expect(css).toContain('--color-action-primary: #1A6DC1')
})
```

- [ ] **Step 2: Run the new test and confirm it fails because the canonical names and values do not exist.**

Run: `./scripts/jt.ps1 npm --prefix frontend test -- src/styles/tokens.spec.ts`

Expected: FAIL with missing canonical token assertions.

- [ ] **Step 3: Replace the legacy token matrix with canonical semantic tokens and compatibility aliases.**

```css
:root[data-theme='light'] {
  color-scheme: light;
  --color-bg-canvas: #FFFFFF;
  --color-action-primary: #1A6DC1;
  --color-focus-ring: #1A6DC1;
}
:root[data-theme='dark'] {
  color-scheme: dark;
  --color-bg-canvas: #191919;
  --color-action-primary: #529CCA;
  --color-focus-ring: #79B8E8;
}
```

Define every global-constraint color in both blocks. Map legacy names such as `--color-canvas`, `--color-surface`, `--color-text`, and `--color-action` to their semantic equivalents only during component migration; do not add palette tokens. Define the type roles, 4/8/12/16/20/24/32/40/48/64px spacing scale, 2/4/6/8px radii, canonical shadow levels, 120/180/240ms motion, and forced-colors/reduced-motion rules.

- [ ] **Step 4: Add self-hosted editorial/code faces and update global type defaults.**

Declare Inter, Source Serif 4, and IBM Plex Mono with `font-display: swap`; set `--font-ui`, `--font-editorial`, and `--font-code` with exact Noto fallback stacks. Update dormant landing/pre-auth rules in `style.css` to semantic tokens so future usage cannot revive cream/tan raw colors.

- [ ] **Step 5: Extend the token guard and update the identity contract.**

Make `scripts/check-design-tokens.sh` inspect public Blade/CSS files in addition to Vue components, require all canonical names in both themes, and reject `#000000`, `#814dde`, and Open Sans declarations in public styles. Document the exact matrix, foreground pairings, typography, aliases, and governance in `docs/visual-identity.md`.

- [ ] **Step 6: Run focused checks and commit.**

Run: `./scripts/jt.ps1 npm --prefix frontend test -- src/styles/tokens.spec.ts && bash scripts/check-design-tokens.sh`

Expected: PASS; the guard reports light/dark parity and no raw component/public palette violations.

```bash
git add frontend/src/styles frontend/src/style.css scripts/check-design-tokens.sh docs/visual-identity.md
git commit -m "feat(identity): establish canonical tokens and typography"
```

### Task 2: Implement three-state theme preference and localized selector

**Files:**
- Modify: `frontend/src/composables/useTheme.ts`
- Modify: `frontend/src/composables/useTheme.spec.ts`
- Modify: `frontend/src/components/ThemeToggle.vue`
- Create: `frontend/src/components/ThemeToggle.spec.ts`
- Modify: `frontend/index.html`
- Modify: `resources/views/app.blade.php`
- Modify: `frontend/src/i18n/locales/en.ts`
- Modify: `frontend/src/i18n/locales/pt-BR.ts`

**Interfaces:**
- Consumes: Task 1 `data-theme` token blocks and storage key `jotter-theme`.
- Produces: `useTheme(): { preference: Ref<'light' | 'dark' | 'system'>, resolvedTheme: Readonly<Ref<'light' | 'dark'>>, setPreference(value: ThemePreference): void }`; `ThemeToggle` renders a native labeled select bound to `preference`.

- [ ] **Step 1: Replace binary-theme expectations with failing three-state tests.**

```ts
it('keeps system as the stored preference while applying a concrete theme', async () => {
  const { preference, resolvedTheme, setPreference } = useTheme()
  setPreference('system')
  await nextTick()
  expect(preference.value).toBe('system')
  expect(['light', 'dark']).toContain(resolvedTheme.value)
  expect(document.documentElement.dataset.theme).toBe(resolvedTheme.value)
  expect(localStorage.getItem('jotter-theme')).toBe('system')
})
```

- [ ] **Step 2: Run theme tests and confirm the binary wrapper fails the new contract.**

Run: `./scripts/jt.ps1 npm --prefix frontend test -- src/composables/useTheme.spec.ts`

Expected: FAIL because `preference`, `resolvedTheme`, and `setPreference` do not exist.

- [ ] **Step 3: Implement explicit preference resolution and live system updates.**

Use `matchMedia('(prefers-color-scheme: dark)')` with a change listener only while preference is `system`; set `document.documentElement.dataset.theme` to the resolved concrete value. Remove listeners when the composable scope disposes. Preserve SSR-safe guards around `window`, `document`, and storage access.

- [ ] **Step 4: Replace the icon-only toggle with an accessible localized three-option selector.**

```vue
<label class="theme-control">
  <span class="sr-only">{{ t('theme.preferenceLabel') }}</span>
  <select v-model="preference" :aria-label="t('theme.preferenceLabel')">
    <option value="system">{{ t('theme.system') }}</option>
    <option value="light">{{ t('theme.light') }}</option>
    <option value="dark">{{ t('theme.dark') }}</option>
  </select>
</label>
```

Use 44px target sizing, semantic surface/border/text/focus tokens, wrapping labels, and no directional icon dependency. Add complete `theme.preferenceLabel`, `theme.system`, `theme.light`, and `theme.dark` messages in English and Brazilian Portuguese.

- [ ] **Step 5: Synchronize the dev and production no-flash bootstraps.**

Both document heads must parse `jotter-theme`, accept `system`, resolve it with `matchMedia`, set `data-theme`, and set the appropriate `theme-color` before the app stylesheet. Do not store a value from bootstrap when the key is absent.

- [ ] **Step 6: Run focused tests and commit.**

Run: `./scripts/jt.ps1 npm --prefix frontend test -- src/composables/useTheme.spec.ts src/components/ThemeToggle.spec.ts`

Expected: PASS for persistence, explicit overrides, system changes, label association, and all options.

```bash
git add frontend/src/composables/useTheme.* frontend/src/components/ThemeToggle.* frontend/index.html resources/views/app.blade.php frontend/src/i18n/locales
git commit -m "feat(identity): add system theme preference"
```

### Task 3: Export fully themed public publishing/share pages

**Files:**
- Modify: `resources/views/publish/page.blade.php`
- Modify: `resources/views/publish/publish.css`
- Create: `resources/views/publish/publish-theme.js`
- Modify: `app/Http/Controllers/WorkspacePublishController.php`
- Modify: `tests/Feature/WorkspacePublishTest.php`
- Create: `tests/Feature/PublishedIdentityAssetsTest.php`

**Interfaces:**
- Consumes: Task 1 canonical CSS names and font files; Task 2 storage/resolve algorithm.
- Produces: generated sites containing `publish.css`, `publish-theme.js`, `fonts/`, licenses, and static HTML with `lang`, `dir`, `data-theme`, a native theme selector, `main`, and `article`.

- [ ] **Step 1: Add failing feature tests for exported identity assets and markup.**

```php
$this->assertFileExists($siteDir.'/publish.css');
$this->assertFileExists($siteDir.'/publish-theme.js');
$this->assertStringContainsString('data-theme', $html);
$this->assertStringContainsString('id="publish-theme-preference"', $html);
$this->assertStringContainsString('Source Serif 4', file_get_contents($siteDir.'/publish.css'));
```

- [ ] **Step 2: Run the public publish tests and confirm they fail before new assets exist.**

Run: `./scripts/jt.ps1 test --filter=WorkspacePublishTest && ./scripts/jt.ps1 test --filter=PublishedIdentityAssetsTest`

Expected: FAIL due to missing `publish-theme.js`, selector markup, and canonical public type/token content.

- [ ] **Step 3: Build a static-public theme document.**

`page.blade.php` must use `lang="{{ $locale }}"`, `dir="{{ $direction }}"`, early no-flash bootstrap, a `theme-color` updated by the static script, and a labeled `select#publish-theme-preference` containing `system`, `light`, and `dark`. Use Laravel translation keys for each control label. Keep the Markdown body inside `main.publish-container > article.publish-article`.

- [ ] **Step 4: Replace public black/purple/Open Sans styling with canonical standalone CSS.**

Define the complete canonical matrix in `publish.css`; use `--font-editorial` for article heading/prose, `--font-ui` for controls, `--font-code` for code, a 720px reading column, logical margins/padding, table/code overflow regions, persistent link underlines, reduced motion, and forced-colors support. Do not import Vite CSS or use a CDN.

- [ ] **Step 5: Copy static assets safely for every generated site.**

Add a private controller helper with signature `copyPublishAsset(string $source, string $destination): void` that creates parent directories and calls `copy` only after `is_file($source)`. Copy `publish.css`, `publish-theme.js`, required WOFF2 files, and OFL license files. Add this exact locale-direction helper and pass both values to note and index views:

```php
private function publicDirection(string $locale): string
{
    $language = strtolower(strtok(str_replace('_', '-', $locale), '-'));

    return in_array($language, ['ar', 'he'], true) ? 'rtl' : 'ltr';
}
```

- [ ] **Step 6: Run public tests and commit.**

Run: `./scripts/jt.ps1 test --filter=WorkspacePublishTest && ./scripts/jt.ps1 test --filter=PublishedIdentityAssetsTest`

Expected: PASS; nested note pages retain correct relative `assetPrefix` links and the index page includes the same public identity assets.

```bash
git add resources/views/publish app/Http/Controllers/WorkspacePublishController.php tests/Feature
git commit -m "feat(identity): theme published pages"
```

### Task 4: Migrate the SPA shell, editor, and data views to the canonical layout contract

**Files:**
- Modify: `frontend/src/App.vue`
- Modify: `frontend/src/components/Sidebar.vue`
- Modify: `frontend/src/components/TabStrip.vue`
- Modify: `frontend/src/components/NoteEditor.vue`
- Modify: `frontend/src/components/MarkdownPreview.vue`
- Modify: `frontend/src/components/CollectionsTableView.vue`
- Modify: `frontend/src/components/CollectionsBoardView.vue`
- Modify: `frontend/src/components/CollectionsCalendarView.vue`
- Modify: related `*.spec.ts` files above

**Interfaces:**
- Consumes: Tasks 1–2 semantic tokens, logical safe-area variables, and `useTheme` preference.
- Produces: responsive shell behavior at 480/768/1024/1280px, 720px prose layout, 1200px data view layout, token-only CSS, and direction-safe controls without changing emitted events or component props.

- [ ] **Step 1: Add failing shell and component assertions.**

```ts
it('keeps the sidebar control keyboard-labelled and 44px on mobile', () => {
  expect(readFileSync('src/App.vue', 'utf8')).toContain('min-block-size: 44px')
  expect(readFileSync('src/App.vue', 'utf8')).toContain("t('app.toggleSidebar')")
})

it('keeps prose constrained while tables declare their own scroll region', () => {
  expect(readFileSync('src/components/NoteEditor.vue', 'utf8')).toContain('max-inline-size: 720px')
  expect(readFileSync('src/components/CollectionsTableView.vue', 'utf8')).toContain('role="region"')
})
```

- [ ] **Step 2: Run component tests and confirm the new canonical assertions fail.**

Run: `./scripts/jt.ps1 npm --prefix frontend test -- src/App.spec.ts src/Sidebar.spec.ts src/NoteEditor.spec.ts src/CollectionsTableView.spec.ts`

Expected: FAIL for missing logical-size/readability/overflow contract assertions.

- [ ] **Step 3: Update the shell using logical layout primitives.**

Use `padding-inline`, `margin-block`, `inset-inline-start`, `min-inline-size`, and `min-block-size`; remove `left`/`right` positioning from new rules. At <480px retain the drawer overlay and collapse nonessential header actions; at 768px choose an overlay/rail; at 1024px keep a persistent collapsible sidebar; at 1280px permit wider secondary layout. Keep the mobile toggle and visible focus behavior.

- [ ] **Step 4: Apply editorial and data-view measures without changing component behavior.**

Set note editor and preview body wrappers to `max-inline-size: 720px` and `margin-inline: auto`. Wrap table content in a named region such as `<div class="collection-table-scroll" role="region" :aria-label="t('collectionsTableView.title')" tabindex="0">`; constrain data views to 1200px; preserve existing events, sorting, paging, drag/drop, and keyboard semantics.

- [ ] **Step 5: Replace remaining visual literals and physical spacing in the touched components.**

Use canonical semantic tokens for default, hover, selected, focus, disabled, status, loading, error, and empty states. Add `dir="auto"` or `<bdi>` around note paths, user-provided titles, URLs, IDs, and code-like values that may occur in RTL UI. Mirror only directional navigation icons via `[dir='rtl']` selectors.

- [ ] **Step 6: Run focused tests and commit.**

Run: `./scripts/jt.ps1 npm --prefix frontend test -- src/App.spec.ts src/Sidebar.spec.ts src/NoteEditor.spec.ts src/CollectionsTableView.spec.ts src/CollectionsBoardView.spec.ts src/CollectionsCalendarView.spec.ts`

Expected: PASS with unchanged interaction/event behavior and new layout assertions.

```bash
git add frontend/src/App.vue frontend/src/components/{Sidebar,TabStrip,NoteEditor,MarkdownPreview,CollectionsTableView,CollectionsBoardView,CollectionsCalendarView}.vue frontend/src/*.spec.ts
git commit -m "feat(identity): align Jotter workspace surfaces"
```

### Task 5: Complete component state, i18n, RTL, and accessibility adoption

**Files:**
- Modify: `frontend/src/components/*.vue` that define menus, dialogs, controls, tags, alerts, panels, skeletons, or empty/error/loading states
- Modify: `frontend/src/a11y.spec.ts`
- Create: `frontend/src/identity-i18n.spec.ts`
- Modify: `frontend/src/i18n/locales/en.ts`
- Modify: `frontend/src/i18n/locales/pt-BR.ts`
- Modify: `frontend/src/test-setup.ts`

**Interfaces:**
- Consumes: Tasks 1–4 semantic token, layout, and theme contracts.
- Produces: message-complete accessible components, pseudo-locale test helpers, and stable IME/bidi behavior without changing the application's API layer.

- [ ] **Step 1: Add failing identity/i18n tests.**

```ts
it.each(['en', 'pt-BR'])('has all theme keys in %s', (locale) => {
  expect(messages[locale].theme).toMatchObject({ preferenceLabel: expect.any(String), system: expect.any(String), light: expect.any(String), dark: expect.any(String) })
})

it('does not transform an input value while IME composition is active', async () => {
  await input.trigger('compositionstart')
  await input.setValue('ภาษาไทย')
  expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['ภาษาไทย'])
})
```

- [ ] **Step 2: Run the focused tests and confirm missing pseudo-locale/bidi/IME coverage fails.**

Run: `./scripts/jt.ps1 npm --prefix frontend test -- src/a11y.spec.ts src/identity-i18n.spec.ts`

Expected: FAIL because the new suite has not yet been created and targeted controls lack the asserted behavior.

- [ ] **Step 3: Add complete state styling and accessible naming.**

For each touched menu, modal, tooltip, button, input, select, tag, callout, toast, skeleton, and status surface, apply semantic state tokens and visible focus. Preserve native controls under `forced-colors: active`; ensure color does not become the only error, selected, or status signal. Add safe live-region semantics for existing success/error banners only if absent; do not add a second announcement path.

- [ ] **Step 4: Add pseudo-locales and direction-aware test setup.**

Create test-only `en-XA` expansion and `ar-XB` RTL message transforms in `test-setup.ts`; do not add them to production locale selection. Mount relevant components with `document.documentElement.lang` and `dir` switched per test. Assert long labels wrap, `bdi` protects mixed values, and only directional icon selectors mirror.

- [ ] **Step 5: Extend structural accessibility coverage.**

Mount `ThemeToggle`, sidebar/menu state, login/forms, an empty state, an error state, a data table scroll region, and the public page markup fixture through axe with `color-contrast` disabled only in jsdom. Assert no serious/critical violations and separately assert canonical token strings for contrast pair governance.

- [ ] **Step 6: Run focused tests and commit.**

Run: `./scripts/jt.ps1 npm --prefix frontend test -- src/a11y.spec.ts src/identity-i18n.spec.ts src/components/ThemeToggle.spec.ts`

Expected: PASS for all locales, focusable controls, pseudo-locales, bidi isolation, and composition behavior.

```bash
git add frontend/src/components frontend/src/a11y.spec.ts frontend/src/identity-i18n.spec.ts frontend/src/test-setup.ts frontend/src/i18n/locales
git commit -m "feat(identity): harden accessible international surfaces"
```

### Task 6: Add browser/release verification and finalize documentation

**Files:**
- Create: `frontend/e2e/identity.spec.ts`
- Modify: `frontend/e2e/smoke.spec.ts`
- Modify: `scripts/check-design-tokens.sh`
- Modify: `docker/release/Dockerfile`
- Modify: `docs/visual-identity.md`
- Modify: `README.md`

**Interfaces:**
- Consumes: all completed identity behavior and public output assets.
- Produces: executable end-to-end acceptance proof and release validation for the canonical identity.

- [ ] **Step 1: Write failing browser acceptance coverage.**

```ts
test('system theme follows OS changes and persists explicit user choices', async ({ page }) => {
  await page.emulateMedia({ colorScheme: 'dark' })
  await page.goto('/')
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark')
  await page.getByLabel(/theme preference/i).selectOption('light')
  await page.reload()
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'light')
})

test('public page reflows at 320px and exposes the static theme selector', async ({ page }) => {
  await page.setViewportSize({ width: 320, height: 800 })
  await page.goto(process.env.PUBLISHED_FIXTURE_URL!)
  await expect(page.locator('#publish-theme-preference')).toBeVisible()
  const width = await page.evaluate(() => ({
    client: document.documentElement.clientWidth,
    scroll: document.documentElement.scrollWidth,
  }))
  expect(width.scroll).toBe(width.client)
})
```

- [ ] **Step 2: Run the identity E2E file and confirm it fails before all page/theme hooks exist.**

Run: `./scripts/jt.ps1 e2e frontend/e2e/identity.spec.ts`

Expected: FAIL because selector/public fixture hooks and assertions have not been wired yet.

- [ ] **Step 3: Implement stable fixture setup and full visual assertions.**

Use the existing bootstrap-admin flow and add a `publishFixture(page: Page): Promise<string>` helper in `identity.spec.ts`: log in as `admin@example.com` / `password12345`, call `page.request.post('/api/workspaces/1/publish')`, assert `response.ok()`, and return `(await response.json()).site_url`. Call it before every public-page navigation. Cover light/dark/system, focus order, 44px targets, contrast through computed canonical pair checks, 200% text zoom, 400% reflow at 320 CSS px, reduced motion, forced colors, `en-XA`, `ar-XB`, CJK wrapping, RTL navigation, mixed-direction content, and IME composition. Avoid screenshots as the sole assertion.

- [ ] **Step 4: Validate release asset inclusion/exclusion.**

Update `docker/release/Dockerfile` only where needed to retain publish runtime assets and OFL licenses while excluding `docs`, `frontend` source, tests, `node_modules`, `.env`, and development evidence. Add an assertion in the release validation path that a generated public site can find `publish.css`, `publish-theme.js`, and its WOFF2 files after extraction.

- [ ] **Step 5: Update handoff documentation and run the complete gate.**

Document token governance, exact theme behavior, typography/license inventory, public export assets, i18n/RTL rules, manual forced-colors/zoom checks, and pseudo-locale scenarios in `docs/visual-identity.md`; link it from `README.md` using a repository-relative link.

Run: `./scripts/jt.ps1 test && bash scripts/check-design-tokens.sh && ./scripts/jt.ps1 e2e frontend/e2e/identity.spec.ts && ./scripts/jt.ps1 release`

Expected: PHP and frontend suites pass, token guard passes, browser identity matrix passes, and `dist/jotter-release.zip` has a matching SHA-256 manifest.

- [ ] **Step 6: Inspect the final diff and commit.**

Run: `git diff --check && git status --short`

Expected: no whitespace errors; only intended identity files are staged.

```bash
git add frontend/e2e scripts/check-design-tokens.sh docker/release/Dockerfile docs/visual-identity.md README.md
git commit -m "test(identity): verify canonical Jotter experience"
```

## Plan self-review

| Approved design requirement | Covered by |
|---|---|
| Exact semantic light/dark tokens, typography, motion, forced colors | Task 1 |
| Persisted light/dark/system and no-flash shells | Task 2 |
| Fully replaced public black/purple pages and exported assets | Task 3 |
| Responsive shell, 720px prose, wide data views | Task 4 |
| i18n, RTL, pseudo-localization, IME, states, axe | Task 5 |
| Browser zoom/reflow/theme checks, release and docs | Task 6 |

The plan contains no deferred work markers, does not alter product data/API behavior, and uses the exact `useTheme` interface introduced in Task 2 consistently in later tasks.
