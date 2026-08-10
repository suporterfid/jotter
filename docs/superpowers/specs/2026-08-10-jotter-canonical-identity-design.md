# Jotter Canonical Visual Identity Design

**Date:** 2026-08-10

**Status:** Approved for planning; implementation waits for user review of this file.

**Canonical source:** `C:\workspace-offline\iroh\notion-inspired-visual-identity-spec.md`

## Goal

Apply the canonical, content-first visual identity to the authenticated Jotter SPA and to every generated public publishing/share page. The result preserves Jotter's original name, wordmark, mark, favicon, product copy, Markdown behavior, and application behavior while replacing the legacy black/purple public theme and incomplete token contract.

## Scope and non-goals

In scope:

- A shared semantic token contract with exact light and dark values from the canonical specification.
- `light`, `dark`, and `system` preference behavior for authenticated and generated public pages.
- The SPA shell, editor, panels, menus, dialogs, collection views, login surface, notifications, and all public publishing/share pages.
- Typography, responsive layout, RTL/i18n structure, forced-colors behavior, reduced motion, token enforcement, test coverage, and release asset validation.

Out of scope:

- Any change to note storage, Markdown rendering semantics, publishing rules, authorization, APIs, data models, or editor feature behavior.
- Notion trademarks, copy, logos, illustrations, icons, proprietary fonts, or screenshots.
- A replacement of Jotter's brand assets. `assets/brand/*` remains Jotter-owned and is not recolored to mimic another product.
- New locales or translations. Existing `en` and `pt-BR` remain complete; the implementation supplies structural readiness and pseudo-locale coverage for future locales.

## Design principles

Jotter becomes quiet, editorial, and content-first. Navigation and supporting panels recede; the current note, its title, and long-form reading carry hierarchy. Warm neutral surfaces establish the base, blue identifies primary actions and links, and status colors remain limited to status. Compact controls retain a 44 by 44 CSS pixel hit target through either their box or an explicitly non-overlapping hit area. Decoration must never compete with notes, collections, or published prose.

## Shared token architecture

`frontend/src/styles/tokens.css` becomes the sole semantic color and foundation contract for the SPA. It defines the complete canonical color matrix in both `:root[data-theme='light']` and `:root[data-theme='dark']`, including canvas, surface, elevated, hover, selected, primary/secondary/disabled/inverse/link text, default/strong borders, primary action states, focus, and success/warning/danger/info foreground/background/border tokens.

Components may only consume semantic tokens. Existing Jotter-facing aliases are either removed or resolve directly to canonical semantic tokens so existing component code can migrate incrementally without raw literals. The token guard expands from its current parity check to reject missing canonical tokens, raw colors in public templates/styles, and component-specific palette bypasses.

The contract also contains the canonical spacing scale, radii, elevation levels, icon sizing, motion values, layer order, typography roles, and logical safe-area variables. The existing 768px rule remains compatible, but the complete responsive shell adopts 480px, 768px, 1024px, and 1280px breakpoints.

## Theme lifecycle

`jotter-theme` stores exactly `light`, `dark`, or `system`. An absent preference behaves as `system`; explicit values are written only after an intentional user selection. A small blocking bootstrap in `frontend/index.html` and `resources/views/app.blade.php` resolves the actual `data-theme` before first paint. A `system` preference listens to `prefers-color-scheme` changes; explicit light or dark does not.

`useTheme()` exposes the preference separately from the resolved theme so the UI can present all three user choices with localized, autonym-safe labels. `ThemeToggle.vue` evolves into an accessible compact selector without changing the surrounding sidebar behavior. The document's `color-scheme` and `theme-color` follow the resolved theme.

Generated public pages receive a small self-contained `publish-theme.js`, copied by `WorkspacePublishController` next to `publish.css`. It applies the same no-flash resolution and keeps a native, keyboard-operable theme selector synchronized with the same storage key when the public page is served on the Jotter origin. If storage is unavailable, it degrades to the operating-system theme without blocking the page.

## Typography and content treatment

The UI stack is Inter plus explicit Noto fallbacks. `Source Serif 4` is self-hosted and used for long-form public reading surfaces, quotes, and editorial article titles; it is not required for app control chrome. `IBM Plex Mono` is self-hosted for code, keys, paths, and fixed-width data. Font loading uses `font-display: swap` and includes license files in the production asset pipeline.

The exact type roles are caption 12/16, compact UI 14/20, body 16/24, section 20/28, subheading 24/32, page title 32/40, and display 44/52, with weights limited to 400, 500, 600, and 700. Public prose uses a 720px maximum inline reading measure. Tables, board views, calendars, and other data-heavy surfaces may expand to 1200px while keeping their own accessible overflow regions.

## Authenticated application shell

The SPA retains its functional Sidebar, TabStrip, NoteEditor, collection views, drawers, dialogs, and panels. It changes only their visual contract:

- The desktop shell keeps a persistent, collapsible sidebar from 1024px upward.
- Between 768px and 1023px the sidebar may be a collapsible rail or overlay; below 768px it is an accessible modal drawer with a focusable toggle and backdrop.
- Below 480px, top-level actions wrap into the existing overflow affordance before labels truncate. Primary task actions stay visible only when they fit.
- Editor and rendered-note prose use a 720px reading measure; secondary panels stack rather than force horizontal page overflow.
- Collection table/board/calendar surfaces use a wider data layout. Horizontal scrolling is permitted only inside a labeled, keyboard-operable table or code region.
- Menus, modals, tooltips, toasts, skeletons, empty states, error states, forms, tags, and callouts gain complete hover, selected, focus-visible, disabled, validation, loading, and reduced-motion states through semantic tokens.

## Public publishing and sharing pages

`resources/views/publish/page.blade.php` becomes a fully themed static document rather than a dark-only wrapper. It declares the resolved `lang`, supports `dir`, includes the early theme bootstrap, exposes a localized theme selector, and keeps the generated article semantically inside `main` and `article`.

`resources/views/publish/publish.css` uses the same canonical token names and type contract as the SPA; it must not define black/purple/Open Sans palette values. It covers heading rhythm, prose, lists, task lists, blockquotes, links with persistent underlines, code, preformatted scroll regions, tables, images, horizontal rules, generated publish index navigation, and a readable empty/error state. The static output stays independent of Vue and external CDNs.

`WorkspacePublishController` copies the public CSS, theme script, required font files, and licenses into each generated site directory. Tests assert these asset names and no public output references unavailable app-only assets.

## Internationalization and RTL

All new visible copy uses existing Vue i18n messages or Laravel message keys. The theme menu labels are complete in `en` and `pt-BR`; language labels use autonyms and never flags. Theme and public-page controls must have accessible names in the document language.

New layout CSS uses logical properties. The app and public template set `dir` from locale/content context. Directional controls alone mirror in RTL; the Jotter brand, close/search/edit/delete icons, code, media, numbers, and charts do not. IDs, URLs, filenames, emails, code, and other mixed-direction values use `bdi` or `dir='auto'` as appropriate.

Controls must handle twice the English length; body copy allows at least 30 percent expansion. Fixed heights that clip translated labels are removed. Pseudo-locale tests cover `en-XA`, `ar-XB`, mixed bidi values, CJK wrapping, Thai/Devanagari input composition, and RTL navigation. The type stacks explicitly accommodate Arabic, Hebrew, CJK, Thai, Devanagari, Latin, Cyrillic, and Greek without character-level styling that breaks shaping.

## Accessibility and platform behavior

The target is WCAG 2.2 AA. Enabled textual foreground/background pairings remain at least 4.5:1. Status is conveyed by text and accessible naming, not by color alone. Every interactive element is keyboard reachable, has visible `:focus-visible` feedback using the focus token, and retains a 44px target.

At 320 CSS pixels / 400 percent zoom, required content remains reachable with no two-dimensional page scrolling. At 200 percent text zoom, controls and translated content wrap without clipping or overlap. Reduced motion disables non-essential animation. Forced-colors mode uses system colors and retains focus, selected, invalid, and state cues without relying on shadows or background colors alone.

IME composition is never interrupted by transforms, validation, submit, or rerender logic in inputs/editors. Existing note editing behavior is preserved.

## Validation strategy

Frontend unit tests cover canonical-token presence and light/dark parity, theme preference resolution and storage, runtime system changes, selector labels, public template/theme script output, and the ban on raw component colors. PHP tests cover public publishing assets and markup. Existing accessibility tests extend to theme selector and key shell/published structures.

Browser verification includes both themes, public and authenticated surfaces, keyboard focus, 200 percent zoom, 400 percent reflow, reduced motion, forced colors, long labels, pseudo-locales, CJK wrapping, RTL directionality, and IME composition. The final release build confirms the exact public assets and licenses ship while development material, tests, evidence, credentials, and vendor/node artifacts remain excluded.

## Acceptance criteria

1. The authenticated SPA and every static publish/share page use the canonical semantic light/dark token matrix with no black/purple legacy public theme.
2. `light`, `dark`, and `system` work before first paint, persist intentionally, and respond correctly to OS changes where `system` applies.
3. App and public reading experiences use original Jotter assets, self-hosted licensed type assets, a 720px prose measure, and complete light/dark support.
4. All touched UI meets the i18n, RTL, keyboard, focus, target-size, contrast, zoom, forced-colors, and reduced-motion requirements in the canonical specification.
5. Docker-only project workflows pass PHP tests, frontend tests, production build, browser identity coverage, and release validation.

## File ownership summary

| Area | Primary files | Responsibility |
|---|---|---|
| Shared foundation | `frontend/src/styles/tokens.css`, `fonts.css`, `frontend/src/style.css` | Canonical tokens, typography, motion, public/pre-auth alignment |
| Theme runtime | `frontend/index.html`, `resources/views/app.blade.php`, `frontend/src/composables/useTheme.ts`, `ThemeToggle.vue` | No-flash light/dark/system behavior and accessible preference control |
| SPA surfaces | `frontend/src/App.vue`, `components/*.vue`, relevant specs | Semantic visual adoption, responsive shell, i18n/RTL and state coverage |
| Public export | `resources/views/publish/page.blade.php`, `publish.css`, `publish-theme.js`, `WorkspacePublishController.php` | Static theme bootstrapping, public reading layout, copied assets |
| Guard and tests | `scripts/check-design-tokens.sh`, frontend specs, PHP publish tests, e2e specs | Token governance and acceptance coverage |
| Documentation | `docs/visual-identity.md` | Implementation contract and handoff checklist |
