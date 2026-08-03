# Jotter Visual Identity

This document describes the visual identity actually implemented in Jotter's
SPA (`frontend/src/`): the design tokens, typography, component patterns,
accessibility rules, and enforcement mechanism. It replaces the earlier
project-neutral "shared spec + Jotter appendix" structure — Jotter's design
has diverged enough from that original baseline (light+dark theming,
neutral accent, Inter) that describing it as an extension of a shared
generic spec no longer reflected reality. This is now Jotter's own
authoritative reference.

History: adopted a shared dark/purple design system (#96, #97–#110,
2026-07-xx), then replanned toward a Notion-inspired light+dark identity
(#233, #234, #235, 2026-07-30/31). See `CHANGELOG.md` and
`docs/superpowers/specs/2026-07-30-notion-visual-identity-design.md` for
the design rationale behind the second pass.

## 1. Principles

- **Clarity first.** Legibility and comprehension outrank decoration.
- **Neutral-first.** Color is not the primary carrier of Jotter's identity —
  layout, spacing, and typography are. The one saturated color in the
  product (`#814DDE` purple) lives on the project mark only, never in
  functional UI.
- **Minimal elevation.** Surfaces are distinguished by border and background
  tint, not drop shadow. Shadow is reserved for genuinely floating content
  (menus, modals, dropdowns) — the one place depth actually communicates
  something (this content is not part of the page flow).
- **Light and dark, both first-class.** Jotter is not a dark-only product
  with a light mode bolted on. Both themes are verified independently
  against the same contrast floor.
- **Accessible by default.** Contrast, focus visibility, semantic HTML,
  and touch targets are load-bearing requirements, checked before every
  merge (§10), not a final QA pass.

## 2. Theme mechanism

Jotter is light+dark, user-toggleable, with OS-preference as the default:

- `data-theme="light"` or `data-theme="dark"` is set on `<html>`.
- **Resolution order:** `localStorage['jotter-theme']` (an explicit user
  choice) → `prefers-color-scheme` → `light`.
- **Before first paint:** an inline blocking script in both HTML shells
  (`frontend/index.html` for dev, `resources/views/app.blade.php` for
  production — the two are kept in sync, per the header comment in each)
  reads `localStorage` and `matchMedia`, then sets the attribute, avoiding
  a flash of the wrong theme.
- **At runtime:** `frontend/src/composables/useTheme.ts` wraps
  `@vueuse/core`'s `useColorMode()` with the same storage key and
  attribute strategy. `ThemeToggle.vue` (in the Sidebar footer) is the one
  UI control that changes it — clicking it writes an explicit `'light'`
  or `'dark'` choice to `localStorage`, which then wins over OS preference
  on every future visit.

## 3. Color

### 3.1 Semantic tokens

Every token below is defined identically in both
`:root[data-theme="light"]` and `:root[data-theme="dark"]` blocks in
`frontend/src/styles/tokens.css` — components reference these tokens only,
never the raw hex values, and never the two palette-construction-only
tokens (`--color-neutral-0`, used solely for text on a filled purple mark
element; anything else is a components-must-not-reference-this token).

| Token | Light | Dark | Role |
|---|---|---|---|
| `--color-canvas` | `#FFFFFF` | `#191919` | Page background |
| `--color-surface` | `#F7F6F3` | `#202020` | Raised panels, cards, sidebar |
| `--color-surface-emphasis` | `#EDECE9` | `#2F2F2F` | Panels that need to stand out (callouts, active state) |
| `--color-text` | `#37352F` | `#D4D4D4` | Primary body text |
| `--color-text-muted` | `#6B6963` | `#9B9B9B` | Secondary / supporting text, metadata |
| `--color-text-inverse` | `#FFFFFF` | `#191919` | Text on a filled `--color-action`/status background |
| `--color-border` | `rgb(55 53 47 / 9%)` | `rgb(255 255 255 / 9%)` | Default dividers and input borders |
| `--color-border-strong` | `rgb(55 53 47 / 16%)` | `rgb(255 255 255 / 16%)` | Emphasized borders (focus-adjacent, active cards) |
| `--color-action` | `#37352F` | `#D4D4D4` | Primary interactive color: links, buttons, focus rings |
| `--color-action-hover` | `#000000` | `#FFFFFF` | Hover/active state for interactive elements |
| `--color-focus` | `#37352F` | `#D4D4D4` | Focus ring color |
| `--color-hover` | `rgb(55 53 47 / 6%)` | `rgb(255 255 255 / 6%)` | Transient row/item hover background (trees, menus, lists) |
| `--color-overlay` | `rgb(55 53 47 / 45%)` | `rgb(0 0 0 / 72%)` | Modal backdrop |
| `--color-overlay-dark` | `rgb(55 53 47 / 60%)` | `rgb(0 0 0 / 85%)` | Heavier backdrop variant |

`--color-action`/`--color-focus`/link color are **neutral** (near-black on
light, near-white on dark) — not purple. This is a deliberate departure
from Jotter's earlier purple-accented identity: color is reserved for the
project mark; functional UI uses a neutral-first palette. `#814DDE`
appears nowhere in `tokens.css` or any component — it survives only in
`assets/brand/mark.svg`, `wordmark.svg`, `favicon.svg` (§8).

`--color-surface-emphasis` and `--color-hover` are similar but distinct:
`surface-emphasis` is for panels/callouts that need to stand out
persistently; `hover` is for a row or item's transient hover/active
background in trees, menus, and lists. Using the wrong one is a common
mistake worth checking for in review.

### 3.2 Status colors

| Token | Light hex | Dark hex | Usage |
|---|---|---|---|
| `--color-status-danger` | `#C0392B` | `#FF5252` | Destructive actions, delete confirmations |
| `--color-status-warning` | `#8F640F` | `#FFB74D` | Warnings, dirty-state indicators |
| `--color-status-success` | `#2E7D32` | `#66BB6A` | Save confirmation, success toasts |
| `--color-status-info` | `#1B6FA8` | `#4FC3F7` | Informational badges, hints |

Never encode information using only color — every status also gets a text
label, icon, or position/shape change. Status text must remain legible in
grayscale.

### 3.3 Verified contrast pairs

All ratios computed with the WCAG 2.1 relative-luminance formula.

| Foreground | Background | Ratio | Passes |
|---|---|---:|---|
| `--color-text` (light) | `--color-canvas` | 12.26:1 | AAA |
| `--color-text-muted` (light) | `--color-canvas` | 5.49:1 | AA |
| `--color-text` (light) | `--color-surface` | 11.35:1 | AAA |
| `--color-text-muted` (light) | `--color-surface` | 5.08:1 | AA |
| `--color-text` (light) | `--color-surface-emphasis` | 10.38:1 | AAA |
| `--color-text-muted` (light) | `--color-surface-emphasis` | 4.65:1 | AA |
| `--color-action-hover` `#000000` (light) | `--color-canvas` | 21.0:1 | AAA |
| `--color-status-danger` (light) `#C0392B` | vs canvas / surface | 5.44:1 / 5.03:1 | AA |
| `--color-status-warning` (light) `#8F640F` | vs canvas / surface | 5.25:1 / 4.86:1 | AA |
| `--color-status-success` (light) `#2E7D32` | vs canvas / surface | 5.13:1 / 4.74:1 | AA |
| `--color-status-info` (light) `#1B6FA8` | vs canvas / surface | 5.40:1 / 5.00:1 | AA |
| `--color-text` (dark) | `--color-canvas` | 11.86:1 | AAA |
| `--color-text-muted` (dark) | `--color-canvas` | 6.33:1 | AA |
| `--color-text` (dark) | `--color-surface` | 10.99:1 | AAA |
| `--color-text-muted` (dark) | `--color-surface` | 5.86:1 | AA |
| `--color-text` (dark) | `--color-surface-emphasis` | 9.03:1 | AAA |
| `--color-text-muted` (dark) | `--color-surface-emphasis` | 4.82:1 | AA |
| `--color-action-hover` `#FFFFFF` (dark) | `--color-canvas` | 17.58:1 | AAA |
| `--color-status-danger` (dark) `#FF5252` | vs canvas / surface | 5.51:1 / 5.11:1 | AA |
| `--color-status-warning` (dark) `#FFB74D` | vs canvas / surface | 10.16:1 / 9.41:1 | AAA |
| `--color-status-success` (dark) `#66BB6A` | vs canvas / surface | 7.44:1 / 6.89:1 | AA |
| `--color-status-info` (dark) `#4FC3F7` | vs canvas / surface | 8.78:1 / 8.13:1 | AAA |

All pairs clear 4.5:1 in both themes. Adding a new token: verify it
against both `canvas` and `surface`, in both themes, and record the ratios
here before merge.

## 4. Typography

Primary typeface: **Inter**, self-hosted, no CDN.

| Weight | Name | Typical use |
|---|---|---|
| 400 | Regular | Body copy |
| 500 | Medium | Emphasis within body copy, form labels |
| 600 | Semibold | Subheadings, button labels |
| 700 | Bold | Headings |

Token: `--font-sans: "Inter", -apple-system, BlinkMacSystemFont,
"Segoe UI", Roboto, Oxygen, Ubuntu, Cantarell, "Helvetica Neue", Arial,
sans-serif`. `--font-mono: ui-monospace, "SFMono-Regular", Consolas,
"Liberation Mono", monospace` for code and hashes.

**Both `body` (`App.vue`) and every page-title `<h2>` (Attachments,
AuditLog, LinkReport, and the three Collections views) must reference
`var(--font-sans)` directly.** Two now-removed tokens, `--font-body` and
`--font-heading`, were referenced by these rules for over a day (since
2026-07-27) without ever being defined in `tokens.css` — an undefined
custom property makes a `font-family` declaration invalid at
computed-value time, so the (inherited) property silently fell back to
whatever `:root` set, which for a long stretch was the landing
stylesheet's Georgia serif (§4.1 note below). Fixed in #235. If a future
component wants a distinct heading treatment, give it its own verified
token — don't reintroduce a name that isn't defined anywhere.

### 4.1 The pre-auth landing page is a deliberate exception

`frontend/src/style.css` ("Jotter landing / static-site styles") is its
own, separate visual treatment — Georgia serif, a cream/tan palette
(`#f3efe5` background, `#24211d` text), none of it built from the tokens
above. Its header comment says "The SPA shell (App.vue) overrides
body/html for the application. This file applies only to the pre-auth
landing surface" — but nothing in the authenticated SPA currently renders
that landing markup path (`.landing`, `.card`, `.eyebrow`, etc. don't
appear in any mounted component today), so in practice this file only
matters if that landing surface is reintroduced. It is explicitly **out
of scope** for this identity: it predates the Notion redesign, was never
migrated to it, and no task in the redesign touched it. If the pre-auth
landing page comes back into active use, it should either adopt the
tokens in §3–§6 or have its divergence recorded here as a real, current
deviation rather than dead code.

### 4.2 Type scale

| Style | Size | Notes |
|---|---|---|
| Display / H1 | `clamp(2.25rem, 1.7rem + 2.4vw, 3.2rem)` | Weight 700, responsive (`--text-h1`) |
| H2 | `clamp(1.75rem, 1.35rem + 1.75vw, 2.5rem)` | Weight 700, responsive (`--text-h2`) |
| Section | `2.5rem` | Static (`--text-section`), weight 700 |
| Subsection | `1.5rem` | Static (`--text-subsection`), weight 700 |
| Lead body | `1.2rem` | `--text-lead`, weight 400, `--color-text-muted` — landing/marketing only |
| Body | `1rem` | `--text-body`, weight 400 — minimum for running prose |
| Small / caption | `0.875rem` | `--text-small`, weight 400, `--color-text-muted` |

`NoteEditor.vue`'s title uses `--text-h1` at the top of the editor toolbar
(the toolbar background matches `--color-canvas`, blending it into the
page rather than framing it as a separate card) — the one place in the
product that reads as a page title the way a Notion page does.

### 4.3 Weight discipline

No more than three font weights in a single view.

### 4.4 Font asset pipeline

`frontend/src/assets/fonts/inter-{400,500,600,700}.woff2` — static,
weight-pinned instances produced with `fonttools varLib.instancer` from
Inter's variable font, then subsetted with `fonttools subset` (Unicode
ranges: Latin-1, Latin Extended-A/B, plus the punctuation/currency/symbol
code points the product's copy uses; layout features kept: `kern`, `liga`,
`calt`, `ccmp`, `mark`, `mkmk`). No CDN, no variable-font `wght` range —
each weight is declared discretely in `frontend/src/styles/fonts.css`.
License: SIL OFL 1.1, copied verbatim to
`frontend/src/assets/fonts/LICENSE.txt` (Inter's own copyright line, "The
Inter Project Authors" — not Open Sans's, which this replaced in #233).

## 5. Spacing

An 8px-based scale, unchanged since the original adoption — it already
fit the Notion-style rhythm, so the redesign didn't touch it:

| Token | Value |
|---|---|
| `--space-1` | 4px |
| `--space-2` | 8px |
| `--space-3` | 12px |
| `--space-4` | 16px |
| `--space-6` | 24px |
| `--space-8` | 32px |
| `--space-12` | 48px |
| `--space-16` | 64px |
| `--space-24` | 96px |

## 6. Radius and elevation

| Token | Value |
|---|---|
| `--radius-sm` | 3px |
| `--radius-md` | 6px |
| `--radius-lg` | 8px |
| `--radius-pill` | 9999px |

`--shadow-float: 0 4px 16px rgb(0 0 0 / 16%), 0 1px 2px rgb(0 0 0 / 8%)` is
the **only** shadow token, and it is reserved exclusively for
floating/overlay elements: modals (`LoginModal`, `AdminPanel`,
`HistoryPanel`'s `.history-card`, `Sidebar`'s `.modal-card`), popovers
(`CommandPalette`, `SlashMenu`), and context menus/dropdowns
(`Sidebar`'s `.more-menu`, `NoteEditor`'s `.autocomplete-dropdown`).
Everything else — cards, panels, static empty states — uses
`--color-border` plus `--color-surface-emphasis`/`--color-hover`, never a
shadow. (This was formerly `--shadow-lg` and was used more broadly before
#233 tightened it — grep for `--shadow-float` before adding a shadow
anywhere; if the thing isn't genuinely floating above the page, it
shouldn't have one.)

## 7. Components

**Buttons.** Primary buttons use `--color-action` fill and
`--color-text-inverse` label text; secondary buttons use a
`--color-border`/`--color-border-strong` outline with `--color-text`
label text on a transparent background. Minimum touch target 44×44px.
Hover moves to `--color-action-hover`, paired with a non-color cue.

**Links.** Inline links use `--color-text` with an underline by default,
switching to `--color-action` on hover/focus.

**Code.** Monospace stack (`--font-mono`) on `--color-surface` with
`--radius-sm` and a subtle `--color-border`.

**Cards and panels.** `--color-surface` for a standard panel,
`--color-surface-emphasis` for one that needs to stand out.

**Sidebar / tree rows (`NoteTreeNode.vue`).** Action icons (add, delete,
"…" menu) are hidden (`opacity: 0`) until the row is hovered or
focus-within, revealing at `opacity: 1` — this is the one interaction
pattern most directly borrowed from Notion's page tree. **Below 768px**
this inverts: touch has no hover state, so action icons render
always-visible at a full 44×44px target instead of hiding behind a hover
that can never fire.

**Panel headers (`PanelHeader.vue`).** A shared component — icon slot,
title, optional count badge — used by the five panels mounted inside
`NoteEditor.vue` that share that visual shape: `BacklinksPanel`,
`CommentsPanel`, `PropertiesPanel`, `OutgoingLinksPanel`,
`UnlinkedMentionsPanel`. **Not** used by `HistoryPanel` (a modal dialog
with a title + close button, not an icon+caption+count row) or by the
three full main-content views — `AttachmentsPanel`, `AuditLogViewer`,
`LinkReportViewer` — which render a page-level `<h2>` title, the same
tier as `NoteEditor`/`GraphView` in `App.vue`'s view-mode switch, not a
docked side panel. Forcing `PanelHeader`'s small uppercase caption style
onto either of those would have been a regression, not a consistency win
— found and deliberately scoped out during #233's implementation.

## 8. Icons, project mark, and images

One icon family throughout (inline SVG, not an icon font). Decorative
icons get `aria-hidden="true"`.

The project mark is **visually separate from the rest of this system** —
`assets/brand/mark.svg`, `mark-monochrome.svg`, `wordmark.svg`,
`favicon.svg`, `social-card.png`. It keeps the original purple
(`#814DDE`) fill unchanged; this is the one place that color still
appears anywhere in the product, deliberately. See
`assets/brand/README.md` for clear-space, minimum-size, and
do-not-recolor rules.

⚠️ `assets/brand/README.md` currently describes the mark's *approved
backgrounds* using the pre-redesign canvas/surface hex values
(`--color-canvas: #000000`, `--color-surface: #1a0a3e`) — those are stale
now that `--color-canvas`/`--color-surface` mean `#FFFFFF`/`#F7F6F3`
(light) or `#191919`/`#202020` (dark). The mark itself is untouched and
correct; only that README's background-hex references need a follow-up
edit — out of scope for this pass, since it wasn't part of any of #233,
#234, or #235's file lists, but worth fixing next time that file is
touched.

## 9. Motion

`--duration-fast: 120ms`, `--duration-standard: 180ms`,
`--duration-slow: 240ms`, `--ease-standard: cubic-bezier(0.2, 0, 0, 1)`.
Motion clarifies a state change; it never exists just to decorate.
`tokens.css` includes a global block that removes non-essential
transitions/animations for `prefers-reduced-motion: reduce` — any new
transition should use the tokens above so it's automatically covered.

## 10. Accessibility

- Semantic HTML before ARIA.
- `lang` and `viewport` on every page (both `frontend/index.html` and
  `resources/views/app.blade.php`).
- Support browser zoom to 200% without loss of content/function.
- Usable at 320px width without horizontal scrolling.
- Never encode information using only color, position, sound, or motion.
- Every interactive element reachable by keyboard, with a visible
  `:focus-visible` ring.
- 44×44px minimum touch target — including hover-reveal icons once they
  render always-visible on touch (§7).

**Automated:** `frontend/src/a11y.spec.ts` runs `axe-core` against 16
mounted structural checks across the SPA's views (Sidebar, LoginModal,
CommandPalette, SearchResults, BacklinksPanel, MarkdownPreview,
AttachmentsPanel, HistoryPanel, PropertiesPanel, CommentsPanel,
AuditLogViewer, LinkReportViewer, and the three Collections views) —
catches invalid ARIA, missing labels, duplicate IDs, malformed headings.
It does **not** catch color-contrast regressions: jsdom doesn't compute
layout/styles, so axe's `color-contrast` rule is skipped in that
environment. Real contrast verification is the manual table in §3.3,
re-derived by hand whenever a token's hex value changes — there is no
automated contrast check in CI today.

## 11. Responsive layout

**Breakpoint: 768px**, used consistently in every `@media (max-width: ...)`
rule in the codebase (`App.vue`, `Sidebar.vue`, `NoteTreeNode.vue`).
There was no pre-existing breakpoint convention when this was introduced
in #233 — this is the first one, so any new responsive rule should reuse
this exact value rather than introducing a second breakpoint.

- **Sidebar**: fixed 280px column on desktop; below 768px becomes an
  off-canvas drawer (`position: fixed`, `translateX(-100%)` at rest,
  `.mobile-open` slides it in), opened by a hamburger button
  (`.mobile-sidebar-toggle` in `App.vue`, hidden above 768px) with a
  full-screen backdrop (`.mobile-sidebar-backdrop`, `--color-overlay`).
  Selecting a note closes the drawer (`handleSelectNote` in `App.vue`).
- **CommandPalette**: `width: min(90vw, 640px)` — already responsive
  before the redesign touched it.
- **SlashMenu**: `width: min(90vw, 260px)` (fixed 260px before #233).
- **NoteTreeNode action icons**: always-visible + 44×44px below 768px
  (§7), instead of hover-reveal.
- **Theme toggle**: lives in the Sidebar footer, which is inside the
  mobile drawer — there is no separate persistent control for small
  viewports.
- **NoteEditor / side panels**: no dedicated mobile treatment beyond what
  they inherit for free. The five `PanelHeader`-using panels (§7) are
  full-width stacked sections below the editor body, not narrow
  right-docked panels, so they already fill the viewport at any width.
  `HistoryPanel`'s modal already sizes itself with
  `width: min(760px, 92vw)` / `height: min(560px, 85vh)`. The editor's
  `.markdown-textarea` and `MarkdownPreview.vue`'s `.markdown-preview`
  both carry `max-width: 760px; margin: 0 auto` (#255), giving the
  classic "Notion page" centered reading column — a `max-width` caps
  rather than forces, so it degrades to full-width automatically below
  760px without a separate media query.

## 12. Assets and packaging

```text
assets/
  brand/
    mark.svg
    mark-monochrome.svg
    wordmark.svg
    favicon.svg
    social-card.png
    README.md
frontend/
  src/
    assets/fonts/
      inter-{400,500,600,700}.woff2
      LICENSE.txt
    styles/
      tokens.css      (theme tokens — §3, §5, §6, §9)
      fonts.css       (@font-face declarations — §4.4)
    style.css          (pre-auth landing surface — §4.1, out of scope)
docs/
  visual-identity.md   (this file)
```

SVG for scalable marks/icons; self-hosted WOFF2 for fonts, no CDN.

## 13. CI enforcement

`./scripts/check-design-tokens.sh` is a standalone, manually-run guard
script (it is **not** currently wired into `.github/workflows/ci.yml` —
running it is a manual step, not an automatic CI gate). It checks:

1. No raw `#hex`/`rgb()`/`rgba()`/`hsl()`/`oklch()` literals in
   `frontend/src/App.vue` or `frontend/src/components/*.vue` (exceptions:
   `transparent`, `currentColor`, or a line annotated
   `/* token-ok: reason */`).
2. No direct palette-token references in components — only
   `--color-neutral-0` is permitted (used for text on filled purple mark
   elements); everything else must go through a semantic token.
3. No third-party font CDN domains (`fonts.googleapis.com`,
   `fonts.gstatic.com`, `fonts.bunny.net`) anywhere under `frontend/`,
   `resources/`, or `public/`.
4. No un-annotated `outline: none`/`outline: 0` (must have a replacement
   focus indicator or an `/* a11y-ok: reason */` comment).
5. Both `:root[data-theme="light"]` and `:root[data-theme="dark"]` blocks
   in `tokens.css` define the exact same set of `--color-*` tokens — a
   token present in one and missing in the other silently falls back to
   an inherited/initial value rather than failing loudly, so this is
   checked explicitly (added in #233, alongside the light/dark rewrite).

## 14. Adoption checklist

- [x] Every semantic token in §3/§5/§6/§9 is defined and consumed in both
      themes — no raw palette values in component code (enforced by §13
      checks 1–2, 5)
- [x] Status colors defined, verified against both `canvas` and
      `surface`, in both themes, ratios recorded (§3.2–3.3)
- [x] Font files shipped for every declared weight; no synthesized
      bold/italic (§4.4)
- [x] No more than three font weights in any single view (§4.3)
- [x] `lang` and viewport present on every page (§10)
- [x] `prefers-reduced-motion: reduce` removes non-essential motion (§9)
- [x] Project mark documented separately with clear-space/do-not rules,
      purple retained deliberately (§8) — *mark README's background-hex
      references are stale, tracked as a follow-up (§8)*
- [ ] Keyboard navigation and visible focus verified by tabbing (not just
      inspection) — not re-verified as part of this rewrite
- [ ] 200% zoom and 320px width verified — not re-verified as part of
      this rewrite
- [ ] Grayscale render carries no information loss — not re-verified as
      part of this rewrite
- [ ] `check-design-tokens.sh` wired into CI (currently manual-only, §13)
- [ ] `.prose` reading-column constraint on `NoteEditor` (§11 — never
      implemented, open item)

## 15. Change history

- **2026-07-27 and earlier** — original dark-only, purple-accented,
  Open-Sans identity adopted (epic #96, issues #97–#110).
- **2026-07-30/31** — Notion-inspired redesign: light+dark theming with
  user toggle, neutral-first accent, self-hosted Inter, minimal
  elevation, mobile layout, shared `PanelHeader`. PRs #233 (implementation),
  #234 (design spec doc, merged after — see
  `docs/superpowers/specs/2026-07-30-notion-visual-identity-design.md`),
  #235 (fixed the pre-existing undefined `--font-body`/`--font-heading`
  tokens that had been silently rendering the whole app in serif since
  2026-07-27, found by comparing production's computed `font-family`
  against source after the redesign shipped and still didn't look right).
  Deployed to `https://hub.taskconnect.com.br/`.
