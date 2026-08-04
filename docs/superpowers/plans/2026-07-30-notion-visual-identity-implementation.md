# Notion-Inspired Visual Identity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Retheme Jotter's SPA to a Notion-inspired light+dark visual identity (neutral-first accent, Inter typeface, minimal elevation) with a user-toggleable theme, and apply matching interaction updates to the sidebar, editor, command palette, and panel components — including mobile-specific layout.

**Architecture:** Two `data-theme` CSS blocks in `tokens.css` (light/dark) replace the current single dark-only `:root` block. Theme resolution/persistence uses `@vueuse/core`'s `useColorMode()` (already a project dependency) instead of hand-rolled `localStorage`/`matchMedia` code. Component changes are CSS-only token migrations except: a new shared `PanelHeader.vue` component (consistent header chrome across panel-like components), a new `ThemeToggle.vue` control, and small template/CSS changes to `Sidebar.vue` (mobile drawer) and `NoteEditor.vue` (title scale).

**Tech Stack:** Vue 3 `<script setup>` + TypeScript, Vite, `@vueuse/core`, Vitest + `@vue/test-utils`, `axe-core`, Playwright, `fonttools` (font subsetting, run via Python — same toolchain the existing Open Sans pipeline used).

## Global Constraints

- Presentation layer only. No API contract changes, no Markdown-on-disk changes, no changes to `docs/visual-identity.md` §8 (Content-Security-Policy / security requirements). (Confirmed: this repo currently emits no CSP header for the SPA shell — no nonce/hash work is needed for the inline theme script.)
- All commands run through the `jt` Docker wrappers per `CLAUDE.md`: use `./scripts/jt.sh npm -- <args>` for frontend package commands and `./scripts/jt.sh test` for the full test suite. Do not run bare `npm`/`vitest` on the host.
- No CDN fonts, ever — self-hosted only, enforced by `scripts/check-design-tokens.sh` check 3.
- `#814DDE` purple is removed from every semantic/functional token. It survives unchanged in `assets/brand/mark.svg`, `wordmark.svg`, `favicon.svg` — do not touch those files in this plan.
- `--shadow-float` (renamed from `--shadow-lg`) is reserved for floating/overlay elements only (modals, popovers, dropdowns, context menus). Everything else uses border + `--color-surface-emphasis`/`--color-hover`, never a shadow.
- Minimum 44×44px touch target for any interactive control, including hover-reveal icons once they render always-visible on touch/mobile.
- `prefers-reduced-motion: reduce` handling in `tokens.css` (lines 79–89) is untouched by this plan — new transitions must use the existing `--duration-*`/`--ease-standard` tokens so they're automatically covered by it.
- Update `STATUS.md` and `BACKLOG.md` when scope changes, per `CLAUDE.md` (final task in this plan).
- Every new/changed color value in a component must be a `var(--color-*)` reference — `scripts/check-design-tokens.sh` enforces this and must pass after every task.

---

## Task 1: Record the final palette and contrast table in the shared spec

This task only edits documentation — it fixes the exact hex values every later task consumes, so it must land first.

**Files:**
- Modify: `docs/visual-identity.md` (Jotter Extensions section, replacing the "Status Colors Extension & Contrast Ratios (#98)" table and the "Departures" subsection)

**Interfaces:**
- Produces: the canonical hex values every later task references. Any later task that writes a hex value must match this table exactly.

- [ ] **Step 1: Replace the "Departures" subsection**

Find:
```markdown
### Departures

*None at present.* All components match the shared specification tokens.
```

Replace with:
```markdown
### Departures

Recorded per §14.5 — see the "Deviation log" table below (2026-07-30 Notion-inspired redesign, tracked in `docs/superpowers/specs/2026-07-30-notion-visual-identity-design.md`).

| Deviation | Reason |
|---|---|
| `--color-action`/`--color-focus`/link color are neutral (near-black light / near-white dark) rather than the shared spec's purple baseline | Notion-parity redesign: color is reserved for the project mark only; functional UI uses a neutral-first palette. |
| Typeface changed from Open Sans to Inter | Visual alignment with the Notion-inspired redesign; self-hosting/no-CDN/subsetting policy (VI4) is preserved unchanged, only the source font differs. |
| `--shadow-float` (formerly `--shadow-lg`) is reserved exclusively for floating/overlay elements; all other surfaces use border + tint only | Notion's elevation language is almost entirely border/tint-based; this tightens Jotter's existing "layering over shadow" guidance from mostly-followed to strictly-enforced. |
```

- [ ] **Step 2: Replace the status-color table and add the theme palette table**

Find:
```markdown
### Status Colors Extension & Contrast Ratios (#98)

Jotter defines four semantic status tokens for alerts, save confirmations, and destructive actions. Each candidate color was selected and verified against both `--color-canvas` (`#000000`) and `--color-surface` (`#1A0A3E`):

| Token | Color | Contrast vs Canvas (`#000000`) | Contrast vs Surface (`#1A0A3E`) | Usage |
|---|---|---:|---:|---|
| `--color-status-danger` | `#FF5252` | 5.86:1 | 5.07:1 | Destructive actions, delete confirmations |
| `--color-status-warning` | `#FFB74D` | 10.73:1 | 9.27:1 | Warnings, dirty state indicators |
| `--color-status-success` | `#66BB6A` | 8.01:1 | 6.92:1 | Save confirmation, success toasts |
| `--color-status-info` | `#4FC3F7` | 11.75:1 | 10.15:1 | Informational badges, hints |
```

Replace with:
```markdown
### Theme Palette (2026-07-30 Notion-inspired redesign)

Jotter is light+dark, user-toggleable (`data-theme="light"|"dark"` on `<html>`). Canvas/surface hex values:

| Token | Light | Dark |
|---|---|---|
| `--color-canvas` | `#FFFFFF` | `#191919` |
| `--color-surface` | `#F7F6F3` | `#202020` |
| `--color-surface-emphasis` | `#EDECE9` | `#2F2F2F` |
| `--color-text` | `#37352F` | `#D4D4D4` |
| `--color-text-muted` | `#6B6963` | `#9B9B9B` |
| `--color-text-inverse` | `#FFFFFF` | `#191919` |
| `--color-action` / `--color-focus` | `#37352F` | `#D4D4D4` |
| `--color-action-hover` | `#000000` | `#FFFFFF` |
| `--color-hover` | `rgb(55 53 47 / 6%)` | `rgb(255 255 255 / 6%)` |

Verified contrast pairs (WCAG 2.1 relative-luminance formula):

| Foreground | Background | Ratio | Passes |
|---|---|---:|---|
| `--color-text` (light) | `--color-canvas` (light) | 12.26:1 | AAA |
| `--color-text-muted` (light) | `--color-canvas` (light) | 5.49:1 | AA |
| `--color-text` (light) | `--color-surface` (light) | 11.35:1 | AAA |
| `--color-text-muted` (light) | `--color-surface` (light) | 5.08:1 | AA |
| `--color-text` (light) | `--color-surface-emphasis` (light) | 10.38:1 | AAA |
| `--color-text-muted` (light) | `--color-surface-emphasis` (light) | 4.65:1 | AA |
| `--color-action-hover` `#000000` (light) | `--color-canvas` (light) | 21.0:1 | AAA |
| `--color-text` (dark) | `--color-canvas` (dark) | 11.86:1 | AAA |
| `--color-text-muted` (dark) | `--color-canvas` (dark) | 6.33:1 | AA |
| `--color-text` (dark) | `--color-surface` (dark) | 10.99:1 | AAA |
| `--color-text-muted` (dark) | `--color-surface` (dark) | 5.86:1 | AA |
| `--color-text` (dark) | `--color-surface-emphasis` (dark) | 9.03:1 | AAA |
| `--color-text-muted` (dark) | `--color-surface-emphasis` (dark) | 4.82:1 | AA |
| `--color-action-hover` `#FFFFFF` (dark) | `--color-canvas` (dark) | 17.58:1 | AAA |

### Status Colors Extension & Contrast Ratios (#98)

Jotter defines four semantic status tokens for alerts, save confirmations, and destructive actions, each independently verified against both canvas and surface **in both themes**:

| Token | Light hex | vs Canvas (`#FFFFFF`) | vs Surface (`#F7F6F3`) | Dark hex | vs Canvas (`#191919`) | vs Surface (`#202020`) |
|---|---|---:|---:|---|---:|---:|
| `--color-status-danger` | `#C0392B` | 5.44:1 | 5.03:1 | `#FF5252` | 5.51:1 | 5.11:1 |
| `--color-status-warning` | `#8F640F` | 5.25:1 | 4.86:1 | `#FFB74D` | 10.16:1 | 9.41:1 |
| `--color-status-success` | `#2E7D32` | 5.13:1 | 4.74:1 | `#66BB6A` | 7.44:1 | 6.89:1 |
| `--color-status-info` | `#1B6FA8` | 5.40:1 | 5.00:1 | `#4FC3F7` | 8.78:1 | 8.13:1 |

All values clear 4.5:1 in both themes. Dark values are unchanged from the pre-redesign table (re-verified against the new `#191919`/`#202020` dark backgrounds, still pass); light values are new.
```

- [ ] **Step 3: Commit**

```bash
git add docs/visual-identity.md
git commit -m "docs: record Notion-redesign palette and contrast table"
```

---

## Task 2: Rewrite `tokens.css` with light/dark theme blocks

**Files:**
- Modify: `frontend/src/styles/tokens.css`

**Interfaces:**
- Produces: `--color-hover` (new token), `--shadow-float` (renamed from `--shadow-lg`), all palette tokens now theme-scoped under `:root[data-theme="light"]` / `:root[data-theme="dark"]` instead of a single `:root` block. `--font-sans` still named `--font-sans` (value changes in Task 4, not here).
- Consumes: nothing new.

- [ ] **Step 1: Replace the whole token block**

Replace lines 1–77 of `frontend/src/styles/tokens.css` (the entire `:root { ... }` block, i.e. everything before the `@media (prefers-reduced-motion: reduce)` block) with:

```css
/*
 * Semantic Design Tokens for Jotter SPA.
 * Specification Rule: Components must reference semantic tokens ONLY.
 * Palette tokens (--color-purple-*, --color-neutral-*) are for theme
 * construction only.
 *
 * Theme selection: `data-theme="light"|"dark"` on <html>, set by
 * useColorMode() (frontend/src/composables/useTheme.ts) before first
 * paint. See frontend/index.html / resources/views/app.blade.php for the
 * blocking bootstrap script.
 */

:root {
  /* Typography (theme-independent) */
  --font-sans: "Inter", -apple-system, BlinkMacSystemFont, "Segoe UI",
    Roboto, Oxygen, Ubuntu, Cantarell, "Helvetica Neue", Arial, sans-serif;
  --font-mono: ui-monospace, "SFMono-Regular", Consolas, "Liberation Mono",
    monospace;

  /* Type scale — §4.2 desktop (Jotter product UI uses body/small, not lead) */
  --text-display: 3.2rem;    /* H1 display: 51.2px, weight 700, lh 1.2 */
  --text-section: 2.5rem;    /* H2 section: 40px, weight 700, lh 1.2, UC */
  --text-subsection: 1.5rem; /* H3 subsection: 24px, weight 700, lh 1.3 */
  --text-lead: 1.2rem;       /* Lead body: 19.2px — marketing / landing only */
  --text-body: 1rem;         /* Body: 16px (minimum for running prose) */
  --text-small: 0.875rem;    /* Metadata / labels: 14px */

  /* Responsive heading sizes via clamp() — §4.3 */
  --text-h1: clamp(2.25rem, 1.7rem + 2.4vw, 3.2rem);
  --text-h2: clamp(1.75rem, 1.35rem + 1.75vw, 2.5rem);

  /* Spacing (theme-independent, unchanged) */
  --space-1: 0.25rem;  --space-2: 0.5rem;   --space-3: 0.75rem;
  --space-4: 1rem;     --space-6: 1.5rem;   --space-8: 2rem;
  --space-12: 3rem;    --space-16: 4rem;    --space-24: 6rem;

  /* Border Radius — shrunk for Notion-style minimal shape */
  --radius-sm: 0.1875rem;  /* 3px */
  --radius-md: 0.375rem;   /* 6px */
  --radius-lg: 0.5rem;     /* 8px */
  --radius-pill: 9999px;

  /* Elevation — reserved for floating/overlay elements ONLY (modals,
     popovers, dropdowns, context menus). Cards/panels use border + tint. */
  --shadow-float: 0 4px 16px rgb(0 0 0 / 16%), 0 1px 2px rgb(0 0 0 / 8%);

  /* Motion (theme-independent, unchanged) */
  --duration-fast: 120ms; --duration-standard: 180ms; --duration-slow: 240ms;
  --ease-standard: cubic-bezier(0.2, 0, 0, 1);

  /* Breakpoint reference (not a real CSS custom property consumer —
     documented here since CSS can't @media on a var(); every
     `@media (max-width: ...)` in this codebase must use 768px). */
}

:root[data-theme="light"] {
  color-scheme: light;

  /* Palette — theme construction only. Components must not reference these. */
  --color-neutral-0: #ffffff;

  /* Semantic — the only tokens components may use. */
  --color-canvas: #ffffff;
  --color-surface: #f7f6f3;
  --color-surface-emphasis: #edece9;
  --color-text: #37352f;
  --color-text-muted: #6b6963;
  --color-text-inverse: #ffffff;
  --color-border: rgb(55 53 47 / 9%);
  --color-border-strong: rgb(55 53 47 / 16%);
  --color-action: #37352f;
  --color-action-hover: #000000;
  --color-focus: #37352f;
  --color-hover: rgb(55 53 47 / 6%);

  /* Status extension tokens (verified ≥ 4.5:1 against canvas & surface) */
  --color-status-danger: #c0392b;
  --color-status-warning: #8f640f;
  --color-status-success: #2e7d32;
  --color-status-info: #1b6fa8;

  /* Overlay tokens (modal backdrop) */
  --color-overlay: rgb(55 53 47 / 45%);
  --color-overlay-dark: rgb(55 53 47 / 60%);
}

:root[data-theme="dark"] {
  color-scheme: dark;

  /* Palette — theme construction only. Components must not reference these. */
  --color-neutral-0: #ffffff;

  /* Semantic — the only tokens components may use. */
  --color-canvas: #191919;
  --color-surface: #202020;
  --color-surface-emphasis: #2f2f2f;
  --color-text: #d4d4d4;
  --color-text-muted: #9b9b9b;
  --color-text-inverse: #191919;
  --color-border: rgb(255 255 255 / 9%);
  --color-border-strong: rgb(255 255 255 / 16%);
  --color-action: #d4d4d4;
  --color-action-hover: #ffffff;
  --color-focus: #d4d4d4;
  --color-hover: rgb(255 255 255 / 6%);

  /* Status extension tokens (verified ≥ 4.5:1 against canvas & surface) */
  --color-status-danger: #ff5252;
  --color-status-warning: #ffb74d;
  --color-status-success: #66bb6a;
  --color-status-info: #4fc3f7;

  /* Overlay tokens (modal backdrop) */
  --color-overlay: rgb(0 0 0 / 72%);
  --color-overlay-dark: rgb(0 0 0 / 85%);
}
```

Leave the `@media (prefers-reduced-motion: reduce)` block (currently lines 79–89) exactly as-is below this.

- [ ] **Step 2: Verify the guard script still passes (it doesn't check tokens.css itself, but confirms nothing else broke)**

Run: `./scripts/jt.sh npm -- run build`
Expected: build succeeds (no CSS syntax errors). This will show `--shadow-lg is undefined` warnings are not a build error in CSS (custom properties don't fail the build when unresolved), but Task 3 removes all remaining `--shadow-lg` references so this is just a sanity build check here.

- [ ] **Step 3: Commit**

```bash
git add frontend/src/styles/tokens.css
git commit -m "feat: replace dark-only token set with light/dark Notion-style palette"
```

---

## Task 3: Rename `--shadow-lg` to `--shadow-float` everywhere, drop shadow from non-floating surfaces

**Files:**
- Modify: `frontend/src/App.vue`
- Modify: `frontend/src/components/HistoryPanel.vue`
- Modify: `frontend/src/components/NoteEditor.vue`
- Modify: `frontend/src/components/Sidebar.vue`
- Modify: `frontend/src/components/AdminPanel.vue`
- Modify: `frontend/src/components/CommandPalette.vue`
- Modify: `frontend/src/components/SlashMenu.vue`
- Modify: `frontend/src/components/LoginModal.vue`

**Interfaces:**
- Consumes: `--shadow-float` from Task 2.

Classification (confirmed by reading each selector's `position`/context):

| File | Selector | Kind | Action |
|---|---|---|---|
| `App.vue` | `.empty-card` | static empty-state card in main content, not floating | **remove** `box-shadow` line entirely |
| `HistoryPanel.vue` | `.history-card` | modal dialog | rename to `--shadow-float` |
| `NoteEditor.vue` | `.autocomplete-dropdown` | `position: absolute` floating dropdown | rename to `--shadow-float` |
| `Sidebar.vue` | `.more-menu` | `position: absolute` context menu (no shadow today — leave as-is, no shadow to rename) | no change |
| `Sidebar.vue` | `.modal-card` | modal dialog | rename to `--shadow-float` |
| `AdminPanel.vue` | `.admin-modal-container` | modal | rename to `--shadow-float` |
| `CommandPalette.vue` | root popover | floating popover | rename to `--shadow-float` |
| `SlashMenu.vue` | root popover | floating popover | rename to `--shadow-float` |
| `LoginModal.vue` | `.login-card` | modal | rename to `--shadow-float` |

- [ ] **Step 1: Remove the shadow from `App.vue`'s `.empty-card`**

In `frontend/src/App.vue`, find:
```css
.empty-card {
  text-align: center;
  max-width: 420px;
  padding: var(--space-12) var(--space-8);
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  box-shadow: var(--shadow-lg);
```

Replace with (drop the `box-shadow` line):
```css
.empty-card {
  text-align: center;
  max-width: 420px;
  padding: var(--space-12) var(--space-8);
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
```

- [ ] **Step 2: Rename the remaining 7 real usages**

Run this from the repo root — it only touches the seven files that keep a shadow (the `App.vue` line was already deleted in Step 1, so `sed` finds nothing there and that's fine):

```bash
sed -i 's/var(--shadow-lg)/var(--shadow-float)/g' \
  frontend/src/components/HistoryPanel.vue \
  frontend/src/components/NoteEditor.vue \
  frontend/src/components/Sidebar.vue \
  frontend/src/components/AdminPanel.vue \
  frontend/src/components/CommandPalette.vue \
  frontend/src/components/SlashMenu.vue \
  frontend/src/components/LoginModal.vue
```

- [ ] **Step 3: Verify no `--shadow-lg` references remain anywhere**

Run: `grep -rn "shadow-lg" frontend/src --include=*.vue --include=*.css`
Expected: no output (empty match).

- [ ] **Step 4: Run the existing component test suite to confirm nothing broke**

Run: `./scripts/jt.sh npm -- test`
Expected: PASS (this is a pure CSS value change, no test asserts on shadow values).

- [ ] **Step 5: Commit**

```bash
git add frontend/src/App.vue frontend/src/components/HistoryPanel.vue frontend/src/components/NoteEditor.vue frontend/src/components/Sidebar.vue frontend/src/components/AdminPanel.vue frontend/src/components/CommandPalette.vue frontend/src/components/SlashMenu.vue frontend/src/components/LoginModal.vue
git commit -m "refactor: rename --shadow-lg to --shadow-float, restrict to floating elements"
```

---

## Task 4: Replace self-hosted Open Sans with self-hosted Inter

**Files:**
- Create: `frontend/src/assets/fonts/inter-400.woff2`, `inter-500.woff2`, `inter-600.woff2`, `inter-700.woff2`
- Create: `frontend/src/assets/fonts/LICENSE.txt` (overwrite existing Open Sans OFL copy with Inter's)
- Delete: `frontend/src/assets/fonts/open-sans-400.woff2`, `open-sans-500.woff2`, `open-sans-600.woff2`, `open-sans-700.woff2`
- Modify: `frontend/src/styles/fonts.css`

**Interfaces:**
- Consumes: `--font-sans` already points to `"Inter", ...` from Task 2 — this task only needs to make that font family actually resolve to shipped files.

- [ ] **Step 1: Fetch and subset Inter using the same pipeline as the existing Open Sans assets**

Run (from repo root; requires `fonttools` — install via `pip install fonttools[woff] brotli` if not already available in the environment used to run `jt` build steps; this is a one-time asset-generation step, not a runtime dependency):

```bash
mkdir -p /tmp/inter-src && cd /tmp/inter-src
curl -fsSL -o Inter.zip "https://github.com/rsms/inter/releases/latest/download/Inter-4.1.zip"
unzip -o Inter.zip -d inter-extracted
# Variable font ships under InterVariable.ttf (weight axis 100-900)
find inter-extracted -iname "InterVariable.ttf"
```

- [ ] **Step 2: Instance each required weight as a static TTF, then subset to WOFF2**

```bash
cd /tmp/inter-src
SRC=$(find inter-extracted -iname "InterVariable.ttf" | head -1)
for weight in 400 500 600 700; do
  fonttools varLib.instancer -o "inter-${weight}-static.ttf" "$SRC" wght=${weight}
  fonttools subset "inter-${weight}-static.ttf" \
    --output-file="inter-${weight}.woff2" \
    --flavor=woff2 \
    --unicodes="U+0000-00FF,U+0100-017F,U+0180-024F,U+2000-206F,U+2074,U+2020,U+20A0-20AB,U+20AD-20CF,U+2113,U+2122,U+2190-2199,U+2212,U+2215,U+FEFF,U+FFFD" \
    --layout-features="kern,liga,calt,ccmp,mark,mkmk"
done
```

(Unicode ranges and layout features intentionally match the existing Open Sans subsetting exactly — same script coverage requirement, just a different source font.)

- [ ] **Step 3: Move the generated files into the repo, remove the old Open Sans files, copy the license**

```bash
cd /home/ubuntu/projects/web/iroh/jotter
mv /tmp/inter-src/inter-400.woff2 /tmp/inter-src/inter-500.woff2 \
   /tmp/inter-src/inter-600.woff2 /tmp/inter-src/inter-700.woff2 \
   frontend/src/assets/fonts/
rm frontend/src/assets/fonts/open-sans-400.woff2 \
   frontend/src/assets/fonts/open-sans-500.woff2 \
   frontend/src/assets/fonts/open-sans-600.woff2 \
   frontend/src/assets/fonts/open-sans-700.woff2
find /tmp/inter-src/inter-extracted -iname "LICENSE.txt" -exec cp {} frontend/src/assets/fonts/LICENSE.txt \;
```

Verify the license file is Inter's SIL OFL 1.1 (not Open Sans's, even though both use the same license family — the copyright holder line differs):

Run: `head -5 frontend/src/assets/fonts/LICENSE.txt`
Expected: mentions "The Inter Project Authors" (not "Digitized data copyright... Open Sans").

- [ ] **Step 4: Rewrite `frontend/src/styles/fonts.css`**

Replace the entire file with:

```css
/* Inter Self-Hosted Font Declarations (SIL OFL 1.1) */

@font-face {
  font-family: 'Inter';
  font-style: normal;
  font-weight: 400;
  font-display: swap;
  src: url('../assets/fonts/inter-400.woff2') format('woff2');
  unicode-range: U+0000-00FF, U+0100-017F, U+0180-024F, U+2000-206F, U+2074, U+2020, U+20A0-20AB, U+20AD-20CF, U+2113, U+2122, U+2190-2199, U+2212, U+2215, U+FEFF, U+FFFD;
}

@font-face {
  font-family: 'Inter';
  font-style: normal;
  font-weight: 500;
  font-display: swap;
  src: url('../assets/fonts/inter-500.woff2') format('woff2');
  unicode-range: U+0000-00FF, U+0100-017F, U+0180-024F, U+2000-206F, U+2074, U+2020, U+20A0-20AB, U+20AD-20CF, U+2113, U+2122, U+2190-2199, U+2212, U+2215, U+FEFF, U+FFFD;
}

@font-face {
  font-family: 'Inter';
  font-style: normal;
  font-weight: 600;
  font-display: swap;
  src: url('../assets/fonts/inter-600.woff2') format('woff2');
  unicode-range: U+0000-00FF, U+0100-017F, U+0180-024F, U+2000-206F, U+2074, U+2020, U+20A0-20AB, U+20AD-20CF, U+2113, U+2122, U+2190-2199, U+2212, U+2215, U+FEFF, U+FFFD;
}

@font-face {
  font-family: 'Inter';
  font-style: normal;
  font-weight: 700;
  font-display: swap;
  src: url('../assets/fonts/inter-700.woff2') format('woff2');
  unicode-range: U+0000-00FF, U+0100-017F, U+0180-024F, U+2000-206F, U+2074, U+2020, U+20A0-20AB, U+20AD-20CF, U+2113, U+2122, U+2190-2199, U+2212, U+2215, U+FEFF, U+FFFD;
}
```

- [ ] **Step 5: Confirm the external-font-CDN guard still passes and the build succeeds**

Run: `./scripts/check-design-tokens.sh`
Expected: `✅ All Visual Identity CI Guards PASSED.`

Run: `./scripts/jt.sh npm -- run build`
Expected: build succeeds with the new font files bundled.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/assets/fonts frontend/src/styles/fonts.css
git commit -m "feat: replace self-hosted Open Sans with self-hosted Inter"
```

---

## Task 5: Theme resolution, persistence, and toggle control

**Files:**
- Create: `frontend/src/composables/useTheme.ts`
- Create: `frontend/src/components/ThemeToggle.vue`
- Create: `frontend/src/composables/useTheme.spec.ts`
- Modify: `frontend/index.html`
- Modify: `resources/views/app.blade.php`

**Interfaces:**
- Produces: `useTheme(): { mode: Ref<'light'|'dark'> }` from `frontend/src/composables/useTheme.ts`, wrapping `@vueuse/core`'s `useColorMode`. Note on `useColorMode`'s actual behavior (verified by reading `node_modules/@vueuse/core/dist/index.js`, since this differs subtly from a naive reading of its docs): the returned ref's getter always resolves to `'light'`/`'dark'` (never the literal string `'auto'`) unless the `emitAuto` option is passed — the raw stored preference (which *can* be `'auto'`) lives on `.store`, a property this wrapper does not expose because nothing downstream needs to distinguish "explicit dark" from "auto-resolved-to-dark". Writing `mode.value = 'light' | 'dark'` still works as an explicit override and persists to `localStorage`.
- Produces: `ThemeToggle.vue` — a self-contained button component with no props, consumed by `Sidebar.vue` in Task 8.

- [ ] **Step 1: Write the failing test for the composable**

Create `frontend/src/composables/useTheme.spec.ts`:

```typescript
import { describe, it, expect, beforeEach } from 'vitest'
import { useTheme } from './useTheme'

describe('useTheme', () => {
  beforeEach(() => {
    localStorage.clear()
    document.documentElement.removeAttribute('data-theme')
  })

  it('resolves to a concrete light/dark value by default (never the literal "auto")', () => {
    const { mode } = useTheme()
    expect(['light', 'dark']).toContain(mode.value)
  })

  it('setting mode to dark sets data-theme="dark" on <html>', () => {
    const { mode } = useTheme()
    mode.value = 'dark'
    expect(document.documentElement.getAttribute('data-theme')).toBe('dark')
  })

  it('setting mode to light sets data-theme="light" on <html>', () => {
    const { mode } = useTheme()
    mode.value = 'light'
    expect(document.documentElement.getAttribute('data-theme')).toBe('light')
  })

  it('persists an explicit choice to localStorage', () => {
    const { mode } = useTheme()
    mode.value = 'dark'
    expect(localStorage.getItem('jotter-theme')).toBe('dark')
  })
})
```

- [ ] **Step 2: Run it to verify it fails**

Run: `./scripts/jt.sh npm -- test -- useTheme`
Expected: FAIL with "Cannot find module './useTheme'" (file doesn't exist yet).

- [ ] **Step 3: Write `useTheme.ts`**

Create `frontend/src/composables/useTheme.ts`:

```typescript
import { useColorMode } from '@vueuse/core'

/**
 * Wraps @vueuse/core's useColorMode with Jotter's storage key and attribute
 * strategy. Resolution order: localStorage['jotter-theme'] (explicit user
 * choice) -> prefers-color-scheme -> 'light'.
 */
export function useTheme() {
  const mode = useColorMode({
    selector: 'html',
    attribute: 'data-theme',
    storageKey: 'jotter-theme',
    modes: {
      light: 'light',
      dark: 'dark',
    },
    initialValue: 'auto',
  })

  return { mode }
}
```

- [ ] **Step 4: Run the test again to verify it passes**

Run: `./scripts/jt.sh npm -- test -- useTheme`
Expected: PASS (4 tests).

- [ ] **Step 5: Write `ThemeToggle.vue`**

Create `frontend/src/components/ThemeToggle.vue`:

```vue
<template>
  <button
    type="button"
    class="theme-toggle"
    :aria-label="isDark ? 'Switch to light theme' : 'Switch to dark theme'"
    :title="isDark ? 'Switch to light theme' : 'Switch to dark theme'"
    @click="toggle"
  >
    <svg v-if="isDark" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
      <circle cx="12" cy="12" r="5"></circle>
      <line x1="12" y1="1" x2="12" y2="3"></line>
      <line x1="12" y1="21" x2="12" y2="23"></line>
      <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line>
      <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line>
      <line x1="1" y1="12" x2="3" y2="12"></line>
      <line x1="21" y1="12" x2="23" y2="12"></line>
      <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line>
      <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line>
    </svg>
    <svg v-else viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
      <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>
    </svg>
  </button>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useTheme } from '../composables/useTheme'

const { mode } = useTheme()
const isDark = computed(() => mode.value === 'dark')

function toggle() {
  mode.value = isDark.value ? 'light' : 'dark'
}
</script>

<style scoped>
.theme-toggle {
  background: transparent;
  border: none;
  color: var(--color-text-muted);
  cursor: pointer;
  padding: var(--space-1);
  border-radius: var(--radius-sm);
  display: flex;
  align-items: center;
  justify-content: center;
  min-width: 44px;
  min-height: 44px;
  transition: color var(--duration-fast) var(--ease-standard),
              background-color var(--duration-fast) var(--ease-standard);
}

.theme-toggle:hover {
  color: var(--color-text);
  background: var(--color-hover);
}
</style>
```

- [ ] **Step 6: Add the blocking pre-paint script to both HTML shells**

In `frontend/index.html`, replace:
```html
    <meta name="color-scheme" content="dark" />
    <meta name="theme-color" content="#000000" />
```
with:
```html
    <meta name="color-scheme" content="light dark" />
    <meta name="theme-color" content="#ffffff" />
    <script>
      (function () {
        var stored = localStorage.getItem('jotter-theme');
        var theme = stored === 'light' || stored === 'dark'
          ? stored
          : (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
        document.documentElement.setAttribute('data-theme', theme);
      })();
    </script>
```

In `resources/views/app.blade.php`, replace:
```html
    <meta name="color-scheme" content="dark">
    <meta name="theme-color" content="#000000">
```
with the identical block (same inline script, Blade has no dynamic content to inject here):
```html
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" content="#ffffff">
    <script>
      (function () {
        var stored = localStorage.getItem('jotter-theme');
        var theme = stored === 'light' || stored === 'dark'
          ? stored
          : (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
        document.documentElement.setAttribute('data-theme', theme);
      })();
    </script>
```

Both files carry an existing header comment reminding contributors to keep them in sync (`<!-- Dev-server HTML Shell. Keep synchronized with resources/views/app.blade.php -->` and its counterpart) — this edit must land in both in the same commit for that reason.

- [ ] **Step 7: Run the full frontend test suite**

Run: `./scripts/jt.sh npm -- test`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add frontend/src/composables/useTheme.ts frontend/src/composables/useTheme.spec.ts frontend/src/components/ThemeToggle.vue frontend/index.html resources/views/app.blade.php
git commit -m "feat: add light/dark theme toggle with localStorage + OS-preference resolution"
```

---

## Task 6: Update the CI design-token guard for the two-theme structure

**Files:**
- Modify: `scripts/check-design-tokens.sh`

**Interfaces:**
- Consumes: nothing new (bash script, no code interfaces).

- [ ] **Step 1: Add a 5th check that fails if a token exists in one theme block but not the other**

In `scripts/check-design-tokens.sh`, find:
```bash
if [ "$ERRORS" -gt 0 ]; then
  echo "❌ Visual Identity CI Guard FAILED with $ERRORS error(s)."
  exit 1
fi
```

Insert immediately before it:
```bash
# 5. Both data-theme blocks must define the same set of --color-* tokens
echo "  [5/5] Checking light/dark theme token parity..."
LIGHT_TOKENS=$(awk '/:root\[data-theme="light"\]/,/^}/' frontend/src/styles/tokens.css | grep -oE '\-\-color-[a-z0-9-]+' | sort -u)
DARK_TOKENS=$(awk '/:root\[data-theme="dark"\]/,/^}/' frontend/src/styles/tokens.css | grep -oE '\-\-color-[a-z0-9-]+' | sort -u)

TOKEN_DIFF=$(diff <(echo "$LIGHT_TOKENS") <(echo "$DARK_TOKENS") || true)

if [ -n "$TOKEN_DIFF" ]; then
  echo "❌ Error: light and dark theme blocks define different --color-* tokens:"
  echo "$TOKEN_DIFF"
  ERRORS=$((ERRORS + 1))
else
  echo "  ✓ Light and dark theme blocks define an identical token set."
fi
```

Also update the four existing progress labels so the count reflects 5 total checks:

```bash
sed -i 's#\[1/4\]#[1/5]#; s#\[2/4\]#[2/5]#; s#\[3/4\]#[3/5]#; s#\[4/4\]#[4/5]#' scripts/check-design-tokens.sh
```

- [ ] **Step 2: Run the guard against the current (Task 2-produced) `tokens.css` and confirm it passes**

Run: `./scripts/check-design-tokens.sh`
Expected: `✅ All Visual Identity CI Guards PASSED.` (all 5 checks report ✓).

- [ ] **Step 3: Prove the new check actually catches a real mismatch (temporary sanity check, not committed)**

```bash
cp frontend/src/styles/tokens.css /tmp/tokens.css.bak
sed -i '0,/--color-hover: rgb(55 53 47 \/ 6%);/{s/--color-hover: rgb(55 53 47 \/ 6%);//}' frontend/src/styles/tokens.css
./scripts/check-design-tokens.sh; echo "exit code: $?"
```
Expected: exit code `1`, with check `[5/5]` reporting the `--color-hover` diff.

Restore the file:
```bash
mv /tmp/tokens.css.bak frontend/src/styles/tokens.css
./scripts/check-design-tokens.sh
```
Expected: passes again.

- [ ] **Step 4: Commit**

```bash
git add scripts/check-design-tokens.sh
git commit -m "feat: add light/dark token-parity check to design-token CI guard"
```

---

## Task 7: NoteTreeNode — hover-reveal actions, `--color-hover`, mobile always-visible icons

**Files:**
- Modify: `frontend/src/components/NoteTreeNode.vue`

**Interfaces:**
- Consumes: `--color-hover` (Task 2).

- [ ] **Step 1: Swap hover background token from `--color-surface-emphasis` to `--color-hover`**

Find (lines 124–127):
```css
.folder-row:hover {
  background: var(--color-surface-emphasis);
  color: var(--color-text);
}
```
Replace with:
```css
.folder-row:hover {
  background: var(--color-hover);
  color: var(--color-text);
}
```

Find (lines 173–176):
```css
.note-item:hover {
  background: var(--color-surface-emphasis);
  color: var(--color-text);
}
```
Replace with:
```css
.note-item:hover {
  background: var(--color-hover);
  color: var(--color-text);
}
```

- [ ] **Step 2: Make the delete button always-visible and a full touch target on mobile**

Find (lines 205–222):
```css
.btn-delete {
  background: transparent;
  border: none;
  color: var(--color-text-muted);
  padding: var(--space-1);
  border-radius: var(--radius-sm);
  cursor: pointer;
  opacity: 0;
  flex-shrink: 0;
  transition: color var(--duration-fast) var(--ease-standard),
              background-color var(--duration-fast) var(--ease-standard),
              opacity var(--duration-fast) var(--ease-standard);
  min-width: 28px;
  min-height: 28px;
  display: flex;
  align-items: center;
  justify-content: center;
}
```
Replace with:
```css
.btn-delete {
  background: transparent;
  border: none;
  color: var(--color-text-muted);
  padding: var(--space-1);
  border-radius: var(--radius-sm);
  cursor: pointer;
  opacity: 0;
  flex-shrink: 0;
  transition: color var(--duration-fast) var(--ease-standard),
              background-color var(--duration-fast) var(--ease-standard),
              opacity var(--duration-fast) var(--ease-standard);
  min-width: 28px;
  min-height: 28px;
  display: flex;
  align-items: center;
  justify-content: center;
}

/* Touch devices have no hover state — the action must be visible and a
   full 44x44 touch target, not a 28px icon hidden behind a hover it can
   never receive. */
@media (max-width: 768px) {
  .btn-delete {
    opacity: 1;
    min-width: 44px;
    min-height: 44px;
  }
}
```

- [ ] **Step 3: Run the existing test suite (no dedicated spec file exists for this component today — this confirms the wider suite, including any App.vue integration tests, still passes)**

Run: `./scripts/jt.sh npm -- test`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add frontend/src/components/NoteTreeNode.vue
git commit -m "style: migrate NoteTreeNode hover state to --color-hover, always-show actions on touch"
```

---

## Task 8: Sidebar — mobile off-canvas drawer + theme toggle in footer

**Files:**
- Modify: `frontend/src/components/Sidebar.vue`
- Modify: `frontend/src/App.vue`

**Interfaces:**
- Consumes: `ThemeToggle.vue` (Task 5).
- Produces: `Sidebar.vue` gains a new optional prop `isMobileSidebarOpen?: boolean` (no new emit — closing the drawer is driven by `App.vue`'s own `handleSelectNote`, since note selection already flows through the existing `select-note` emit chain). `App.vue` introduces new local state `isMobileSidebarOpen: Ref<boolean>` (desktop-irrelevant; only read below the 768px breakpoint) and a new hamburger button.

- [ ] **Step 1: Add the theme toggle to the sidebar footer**

In `frontend/src/components/Sidebar.vue`, find (around line 353–365):
```html
    <div v-if="currentUser" class="sidebar-footer">
      <div class="user-badge" data-testid="user-profile">
        <span class="user-name">{{ currentUser.name }}</span>
        <span class="user-email">{{ currentUser.email }}</span>
      </div>
      <button class="btn-logout" data-testid="logout-btn" title="Sign Out" @click="$emit('logout')">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
          <polyline points="16 17 21 12 16 7"></polyline>
          <line x1="21" y1="12" x2="9" y2="12"></line>
        </svg>
      </button>
    </div>
```
Replace with:
```html
    <div v-if="currentUser" class="sidebar-footer">
      <div class="user-badge" data-testid="user-profile">
        <span class="user-name">{{ currentUser.name }}</span>
        <span class="user-email">{{ currentUser.email }}</span>
      </div>
      <div class="sidebar-footer-actions">
        <ThemeToggle />
        <button class="btn-logout" data-testid="logout-btn" title="Sign Out" @click="$emit('logout')">
          <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
            <polyline points="16 17 21 12 16 7"></polyline>
            <line x1="21" y1="12" x2="9" y2="12"></line>
          </svg>
        </button>
      </div>
    </div>
```

Add the import in the `<script setup>` block, next to the existing `NoteTreeNode` import (around line 372):
```typescript
import NoteTreeNode from './NoteTreeNode.vue'
import type { TreeFolder, TreeNode } from './NoteTreeNode.vue'
import ThemeToggle from './ThemeToggle.vue'
```

Add the layout CSS for the new wrapper, immediately after the existing `.sidebar-footer { ... }` block (around line 1125):
```css
.sidebar-footer-actions {
  display: flex;
  align-items: center;
  gap: var(--space-1);
}
```

- [ ] **Step 2: Make the sidebar an off-canvas drawer below 768px**

Find (lines 564–572):
```css
.sidebar {
  width: 280px;
  min-width: 280px;
  background: var(--color-surface);
  border-right: 1px solid var(--color-border);
  display: flex;
  flex-direction: column;
  height: 100%;
}
```
Replace with:
```css
.sidebar {
  width: 280px;
  min-width: 280px;
  background: var(--color-surface);
  border-right: 1px solid var(--color-border);
  display: flex;
  flex-direction: column;
  height: 100%;
}

@media (max-width: 768px) {
  .sidebar {
    position: fixed;
    inset: 0 auto 0 0;
    z-index: 40;
    width: min(85vw, 320px);
    min-width: 0;
    transform: translateX(-100%);
    transition: transform var(--duration-standard) var(--ease-standard);
  }

  .sidebar.mobile-open {
    transform: translateX(0);
  }
}
```

Add `:class="{ 'mobile-open': isMobileSidebarOpen }"` to the root `<aside class="sidebar">` element (line 2), and accept a new prop for it. Find (line 2):
```html
  <aside class="sidebar">
```
Replace with:
```html
  <aside class="sidebar" :class="{ 'mobile-open': isMobileSidebarOpen }">
```

Add the prop to the `defineProps` block. Find (around line 375–380):
```typescript
const props = defineProps<{
  notes: NoteMeta[]
  selectedNoteId: number | null
  currentUser?: AuthUser | null
  notifications?: NotificationItem[]
}>()
```
Replace with:
```typescript
const props = defineProps<{
  notes: NoteMeta[]
  selectedNoteId: number | null
  currentUser?: AuthUser | null
  notifications?: NotificationItem[]
  isMobileSidebarOpen?: boolean
}>()
```

- [ ] **Step 3: Wire a hamburger toggle and backdrop into `App.vue`**

In `frontend/src/App.vue`, find the `<Sidebar ... />` opening tag (around line 3) and add the new prop/listener:
```html
    <Sidebar
      :notes="notes"
      :selected-note-id="activeNoteId"
      :current-user="currentUser"
      :notifications="notifications"
      :is-mobile-sidebar-open="isMobileSidebarOpen"
```
(insert as a new line right after `:notifications="notifications"`, before the existing `@select-note` listener line).

Add a backdrop element and hamburger button. Find the root template opening:
```html
<template>
  <div class="app-layout">
```
Replace with:
```html
<template>
  <div class="app-layout">
    <button
      type="button"
      class="mobile-sidebar-toggle"
      aria-label="Toggle sidebar"
      @click="isMobileSidebarOpen = !isMobileSidebarOpen"
    >
      <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2">
        <line x1="3" y1="6" x2="21" y2="6"></line>
        <line x1="3" y1="12" x2="21" y2="12"></line>
        <line x1="3" y1="18" x2="21" y2="18"></line>
      </svg>
    </button>
    <div
      v-if="isMobileSidebarOpen"
      class="mobile-sidebar-backdrop"
      @click="isMobileSidebarOpen = false"
    ></div>
```

Add the state and a `select-note` side effect (closing the drawer after navigating on mobile) in the `<script setup>` block — add near the other `ref()` declarations:
```typescript
const isMobileSidebarOpen = ref(false)
```

Find the existing `handleSelectNote` function and add a line that closes the mobile drawer (this keeps existing desktop behavior identical — the ref is only ever `true` on mobile since the hamburger button that sets it is hidden above 768px per Step 4 below):
```typescript
function handleSelectNote(noteId: number) {
  isMobileSidebarOpen.value = false
  // ...existing body of handleSelectNote continues unchanged below this line
```

- [ ] **Step 4: Add the CSS for the hamburger button and backdrop, hidden on desktop**

Add to `frontend/src/App.vue`'s `<style>` block, near `.app-layout`:
```css
.mobile-sidebar-toggle {
  display: none;
}

.mobile-sidebar-backdrop {
  display: none;
}

@media (max-width: 768px) {
  .mobile-sidebar-toggle {
    display: flex;
    align-items: center;
    justify-content: center;
    position: fixed;
    top: var(--space-3);
    left: var(--space-3);
    z-index: 50;
    min-width: 44px;
    min-height: 44px;
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    color: var(--color-text);
    cursor: pointer;
  }

  .mobile-sidebar-backdrop {
    display: block;
    position: fixed;
    inset: 0;
    z-index: 30;
    background: var(--color-overlay);
  }
}
```

- [ ] **Step 5: Run the existing App-level and Sidebar-level tests**

Run: `./scripts/jt.sh npm -- test -- App`
Expected: PASS — `App.spec.ts` exercises `handleSelectNote`/notes flows; confirm the new `isMobileSidebarOpen.value = false` line at the top of `handleSelectNote` didn't change any assertions (it only writes a ref nothing in that spec reads).

Run: `./scripts/jt.sh npm -- test -- Sidebar`
Expected: PASS (search for existing `Sidebar*.spec.ts` — `SidebarNotifications.spec.ts` is the one present; confirm it still passes with the new prop, which is optional and defaults to falsy).

- [ ] **Step 6: Commit**

```bash
git add frontend/src/components/Sidebar.vue frontend/src/App.vue
git commit -m "feat: add mobile off-canvas sidebar drawer and theme toggle in sidebar footer"
```

---

## Task 9: NoteEditor — larger title, canvas-blended toolbar

**Files:**
- Modify: `frontend/src/components/NoteEditor.vue`

**Interfaces:**
- Consumes: `--text-h1` (existing token, unchanged value).

- [ ] **Step 1: Enlarge the title and blend the toolbar into the canvas**

Find (lines 598–620):
```css
.editor-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: var(--space-3) var(--space-6);
  background: var(--color-surface);
  border-bottom: 1px solid var(--color-border);
}

.note-meta-info {
  display: flex;
  flex-direction: column;
}

.editor-title {
  margin: 0;
  font-size: 1.125rem;
  font-weight: 700;
  color: var(--color-text);
}
```
Replace with:
```css
.editor-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: var(--space-3) var(--space-6);
  background: var(--color-canvas);
  border-bottom: 1px solid var(--color-border);
}

.note-meta-info {
  display: flex;
  flex-direction: column;
}

.editor-title {
  margin: 0;
  font-size: var(--text-h1);
  font-weight: 700;
  line-height: 1.15;
  color: var(--color-text);
}
```

- [ ] **Step 2: Run the NoteEditor-adjacent test suite**

Run: `./scripts/jt.sh npm -- test -- App`
Expected: PASS (`NoteEditor` has no dedicated spec file — it's exercised through `App.spec.ts`; the `data-testid="editor-title"` attribute is unchanged, so any test asserting on its text content is unaffected by a font-size change).

- [ ] **Step 3: Commit**

```bash
git add frontend/src/components/NoteEditor.vue
git commit -m "style: enlarge NoteEditor title to page-title scale, blend toolbar into canvas"
```

---

## Task 10: SlashMenu responsive width

**Files:**
- Modify: `frontend/src/components/SlashMenu.vue`

**Interfaces:**
- Consumes: nothing new. (`CommandPalette.vue` already uses `width: min(90vw, 640px)` — confirmed responsive, no change needed there.)

- [ ] **Step 1: Make the fixed 260px width responsive**

Find (around line 111):
```css
  width: 260px;
```
Replace with:
```css
  width: min(90vw, 260px);
```

- [ ] **Step 2: Run the SlashMenu test**

Run: `./scripts/jt.sh npm -- test -- SlashMenu`
Expected: PASS (`SlashMenu.spec.ts` exists and tests behavior, not layout — width change is inert to it).

- [ ] **Step 3: Commit**

```bash
git add frontend/src/components/SlashMenu.vue
git commit -m "style: make SlashMenu width responsive on narrow viewports"
```

---

## Task 11: Shared `PanelHeader.vue` + migrate `BacklinksPanel.vue` (worked example)

This establishes the shared header pattern for the panel family mounted inside `NoteEditor.vue` (Backlinks, Comments, History, Properties, OutgoingLinks, UnlinkedMentions — confirmed by `grep` during planning: all six are imported and rendered by `NoteEditor.vue`, not docked separately by `App.vue`). Five of the six (all but History) are full-width stacked sections below the editor body (e.g. `BacklinksPanel.vue`'s root has `border-top: 1px solid var(--color-border)`, not a right-hand dock) — they already inherit `NoteEditor`'s responsive full-width column, so **no separate mobile slide-in/bottom-sheet CSS is needed for them**: the design doc's assumption that these were narrow right-docked panels needing their own mobile layout didn't hold up against the actual DOM structure, so this plan corrects course and scopes the work to what's real — consistent header chrome via `PanelHeader`, plus the standard `--color-hover`/`--shadow-float` token migration. `HistoryPanel.vue` is the one true modal in this family (`.history-card`, fixed-size, centered) and its `--shadow-float` rename already happened in Task 3; its `width: min(760px, 92vw)` / `height: min(560px, 85vh)` sizing is already responsive.

This does **not** cover Attachments/AuditLog/LinkReport — those render as full main-content views (see Task 13 for why they're handled separately).

**Files:**
- Create: `frontend/src/components/PanelHeader.vue`
- Create: `frontend/src/components/PanelHeader.spec.ts`
- Modify: `frontend/src/components/BacklinksPanel.vue`

**Interfaces:**
- Produces: `PanelHeader.vue` — props `{ title: string, count?: number }`, slot `icon` (the panel supplies its own SVG icon), emits nothing (no close button — these panels don't currently have one; Task 12 covers panels that do).

- [ ] **Step 1: Write the failing test**

Create `frontend/src/components/PanelHeader.spec.ts`:

```typescript
import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import PanelHeader from './PanelHeader.vue'

describe('PanelHeader', () => {
  it('renders the title', () => {
    const wrapper = mount(PanelHeader, { props: { title: 'Backlinks' } })
    expect(wrapper.text()).toContain('Backlinks')
  })

  it('renders the count badge when count is provided', () => {
    const wrapper = mount(PanelHeader, { props: { title: 'Backlinks', count: 3 } })
    expect(wrapper.find('.panel-header-count').text()).toBe('3')
  })

  it('omits the count badge when count is undefined', () => {
    const wrapper = mount(PanelHeader, { props: { title: 'Backlinks' } })
    expect(wrapper.find('.panel-header-count').exists()).toBe(false)
  })

  it('renders slotted icon content', () => {
    const wrapper = mount(PanelHeader, {
      props: { title: 'Backlinks' },
      slots: { icon: '<svg class="my-icon"></svg>' },
    })
    expect(wrapper.find('.my-icon').exists()).toBe(true)
  })
})
```

- [ ] **Step 2: Run it to verify it fails**

Run: `./scripts/jt.sh npm -- test -- PanelHeader`
Expected: FAIL — `Cannot find module './PanelHeader.vue'`.

- [ ] **Step 3: Write `PanelHeader.vue`**

Create `frontend/src/components/PanelHeader.vue`:

```vue
<template>
  <div class="panel-header">
    <div class="panel-header-title">
      <slot name="icon" />
      <span>{{ title }}</span>
    </div>
    <span v-if="count !== undefined" class="panel-header-count">{{ count }}</span>
  </div>
</template>

<script setup lang="ts">
defineProps<{
  title: string
  count?: number
}>()
</script>

<style scoped>
.panel-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: var(--space-3);
  font-weight: 600;
  color: var(--color-text-muted);
  text-transform: uppercase;
  letter-spacing: 0.05em;
  font-size: 0.75rem;
}

.panel-header-title {
  display: flex;
  align-items: center;
  gap: var(--space-2);
}

.panel-header-count {
  background: var(--color-surface-emphasis);
  color: var(--color-action);
  padding: 0.125rem 0.5rem;
  border-radius: var(--radius-pill);
  font-size: 0.75rem;
}
</style>
```

- [ ] **Step 4: Run the test again to verify it passes**

Run: `./scripts/jt.sh npm -- test -- PanelHeader`
Expected: PASS (4 tests).

- [ ] **Step 5: Migrate `BacklinksPanel.vue` to use it**

In `frontend/src/components/BacklinksPanel.vue`, find:
```html
<template>
  <aside class="backlinks-panel" aria-label="Backlinks">
    <div class="backlinks-header">
      <div class="header-title">
        <svg class="icon" viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" stroke-width="2" fill="none">
          <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path>
          <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path>
        </svg>
        <span>Backlinks</span>
      </div>
      <span class="count-badge">{{ backlinks.length }}</span>
    </div>
```
Replace with:
```html
<template>
  <aside class="backlinks-panel" aria-label="Backlinks">
    <PanelHeader title="Backlinks" :count="backlinks.length">
      <template #icon>
        <svg class="icon" viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" stroke-width="2" fill="none">
          <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path>
          <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path>
        </svg>
      </template>
    </PanelHeader>
```

Add the import to the `<script setup>` block:
```typescript
import PanelHeader from './PanelHeader.vue'
import type { Backlink } from '../services/types'
```

Remove the now-unused header CSS (the `PanelHeader` component owns this styling now). Find and delete:
```css
.backlinks-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: var(--space-3);
  font-weight: 600;
  color: var(--color-text-muted);
  text-transform: uppercase;
  letter-spacing: 0.05em;
  font-size: 0.75rem;
}

.header-title {
  display: flex;
  align-items: center;
  gap: var(--space-2);
}

.count-badge {
  background: var(--color-surface-emphasis);
  color: var(--color-action);
  padding: 0.125rem 0.5rem;
  border-radius: var(--radius-pill);
  font-size: 0.75rem;
}
```

Also swap the list-item hover token to `--color-hover` while in this file. Find:
```css
.backlink-item:hover {
  border-color: var(--color-action);
}
```
Replace with:
```css
.backlink-item:hover {
  border-color: var(--color-action);
  background: var(--color-hover);
}
```

- [ ] **Step 6: Run the guard and full test suite**

Run: `./scripts/check-design-tokens.sh`
Expected: passes.

Run: `./scripts/jt.sh npm -- test`
Expected: PASS (no existing spec targets `BacklinksPanel.vue` directly, per the earlier file search — this is a template-only refactor).

- [ ] **Step 7: Commit**

```bash
git add frontend/src/components/PanelHeader.vue frontend/src/components/PanelHeader.spec.ts frontend/src/components/BacklinksPanel.vue
git commit -m "feat: add shared PanelHeader component, migrate BacklinksPanel"
```

---

## Task 12: Migrate the remaining 5 side panels to `PanelHeader`

Applies the exact `PanelHeader` component from Task 11 to `CommentsPanel.vue`, `HistoryPanel.vue`, `PropertiesPanel.vue`, `OutgoingLinksPanel.vue`, `UnlinkedMentionsPanel.vue`. Each of these currently rolls its own header markup (icon + title + optional count, styled inline) — the exact CSS class names differ per file, so this task is one recipe applied five times rather than five copy-pasted diffs.

**Files:**
- Modify: `frontend/src/components/CommentsPanel.vue`
- Modify: `frontend/src/components/HistoryPanel.vue`
- Modify: `frontend/src/components/PropertiesPanel.vue`
- Modify: `frontend/src/components/OutgoingLinksPanel.vue`
- Modify: `frontend/src/components/UnlinkedMentionsPanel.vue`

**Interfaces:**
- Consumes: `PanelHeader.vue` (Task 11) — props `{ title: string, count?: number }`, slot `icon`.

For **each** of the 5 files, in this exact order:

- [ ] **Step 1: Read the file's current header markup**

Run: `grep -n -A15 "<template>" frontend/src/components/<File>.vue | head -20`

Identify: the header `<div>`/wrapper element (usually the first child of the root), its icon `<svg>`, its title text or binding, and any count/badge element.

- [ ] **Step 2: Replace the header markup with `<PanelHeader>`**

Wrap the identified title text (or binding, e.g. `{{ someLabel }}`) as the `title` prop, the identified count/badge value as the `:count` prop (omit `:count` entirely if the panel has no count badge — `PanelHeader` renders it conditionally per Task 11 Step 3), and move the icon `<svg>` into the `#icon` template slot, exactly as done for `BacklinksPanel.vue` in Task 11 Step 5.

- [ ] **Step 3: Add the import**

```typescript
import PanelHeader from './PanelHeader.vue'
```

- [ ] **Step 4: Delete the file's now-unused header-specific CSS rules** (the selectors that styled the markup removed in Step 2 — e.g. a `.panel-header`/`.header-title`/`.count-badge`-equivalent block local to that file).

- [ ] **Step 5: Swap any remaining `--color-surface-emphasis` used purely as a *hover* background (not as a static card/callout background) to `--color-hover`**, matching the `BacklinksPanel.vue` precedent — only hover-state rules, not static panel/card backgrounds.

- [ ] **Step 6: Run that file's existing spec**

Run: `./scripts/jt.sh npm -- test -- <ComponentName>`

Expected: PASS. (`CommentsPanel.spec.ts`, `HistoryPanel.spec.ts`, `PropertiesPanel.spec.ts`, `OutgoingLinksPanel.spec.ts`, `UnlinkedMentionsPanel.spec.ts` all exist per the earlier repo scan — each must still pass. If a spec asserts on a CSS class that Step 4 deleted, update the spec's selector to match the new `PanelHeader`-rendered markup rather than reintroducing the deleted class — the assertion's *intent* (title text is shown, count is shown) is what must survive, not the exact old class name.)

- [ ] **Step 7 (after all 5 files are done): Run the guard and full suite once more**

Run: `./scripts/check-design-tokens.sh && ./scripts/jt.sh npm -- test`
Expected: both pass.

- [ ] **Step 8: Commit (single commit covering all 5 files)**

```bash
git add frontend/src/components/CommentsPanel.vue frontend/src/components/HistoryPanel.vue frontend/src/components/PropertiesPanel.vue frontend/src/components/OutgoingLinksPanel.vue frontend/src/components/UnlinkedMentionsPanel.vue
git commit -m "refactor: migrate remaining side panels to shared PanelHeader"
```

---

## Task 13: Header consistency for the full-view panels (Attachments, AuditLog, LinkReport)

Design intent from the spec was "consistent header row across all panel-like surfaces." Research during planning found that `AttachmentsPanel.vue`, `AuditLogViewer.vue`, and `LinkReportViewer.vue` are **not** narrow side panels — in `App.vue`'s `v-else-if` chain they render as full main-content views (same tier as `GraphView`/`NoteEditor`), not docked alongside the editor. They still benefit from the same `PanelHeader` component for visual consistency of their title row; they do **not** need any extra mobile-specific layout work (unlike the Sidebar in Task 8, which needed an off-canvas drawer), because a full main-content view is already "full-screen" on mobile by construction (it already fills `.main-content`, which is 100% width below the sidebar breakpoint).

**Files:**
- Modify: `frontend/src/components/AttachmentsPanel.vue`
- Modify: `frontend/src/components/AuditLogViewer.vue`
- Modify: `frontend/src/components/LinkReportViewer.vue`

**Interfaces:**
- Consumes: `PanelHeader.vue` (Task 11).

Apply the identical 6-step recipe from Task 12 to these 3 files (read current header markup → replace with `PanelHeader` → add import → delete dead CSS → swap hover-only `--color-surface-emphasis` to `--color-hover` → run that file's spec: `AttachmentsPanel.spec.ts`, `AuditLogViewer.spec.ts`, `LinkReportViewer.spec.ts` all exist per the earlier repo scan).

- [ ] **Step 1–6 (×3 files):** as described above.

- [ ] **Step 7: Run the guard and full suite**

Run: `./scripts/check-design-tokens.sh && ./scripts/jt.sh npm -- test`
Expected: both pass.

- [ ] **Step 8: Commit**

```bash
git add frontend/src/components/AttachmentsPanel.vue frontend/src/components/AuditLogViewer.vue frontend/src/components/LinkReportViewer.vue
git commit -m "refactor: migrate full-view panel headers to shared PanelHeader"
```

---

## Task 14: `docs/visual-identity.md` — finalize the "Jotter Extensions" narrative + STATUS.md/BACKLOG.md

**Files:**
- Modify: `docs/visual-identity.md` (Implementation Tracking checklist)
- Modify: `STATUS.md`
- Modify: `BACKLOG.md` (only if it still references the pre-redesign visual identity as open work — check first)

**Interfaces:** none (documentation only).

- [ ] **Step 1: Add a new Implementation Tracking entry**

In `docs/visual-identity.md`, find the "Implementation Tracking (#96–#110)" checklist's closing line:
```markdown
- [x] Verification — #109 WCAG 2.2 AA audit (acceptance gate), #110 CI token guard (lands last).
```
Add immediately after it:
```markdown

### 2026-07-30 Notion-Inspired Redesign

Full replan of the visual identity toward Notion's visual language: light+dark
theming with user toggle, neutral-first accent (purple retained only on the
project mark), Inter typeface, minimal elevation, and mobile-specific layout
for the sidebar and panels. Spec:
`docs/superpowers/specs/2026-07-30-notion-visual-identity-design.md`. Plan:
`docs/superpowers/plans/2026-07-30-notion-visual-identity-implementation.md`.

- [x] Foundation — palette/contrast table, token layer, `--shadow-float` rename, Inter font pipeline, theme toggle mechanism, CI guard update.
- [x] Components — Sidebar mobile drawer, NoteTreeNode hover/touch targets, NoteEditor title scale, CommandPalette/SlashMenu responsive width, shared `PanelHeader` across 9 panel-like components.
- [x] Verification — design-token guard passes, full frontend test suite passes.
```

- [ ] **Step 2: Add a `STATUS.md` entry**

Find the most recent bullet list entry in `STATUS.md` documenting a shipped feature (the format is `- Category: description (#issue — implementation summary)`, visible at the lines referencing #108/#109/#110 found during planning). Add a new bullet immediately above/below that block, following the same format:

```markdown
  - Visual Identity: Notion-inspired redesign — light/dark theming, neutral accent, Inter typeface, minimal elevation, mobile layout (2026-07-30 — `docs/superpowers/specs/2026-07-30-notion-visual-identity-design.md`, `scripts/check-design-tokens.sh` extended with theme-parity check)
```

- [ ] **Step 3: Check `BACKLOG.md` for stale references**

Run: `grep -n -i "visual identity\|notion" BACKLOG.md`

If any line references the old (purple/dark-only/Open Sans) identity as still-open work, update or remove it to avoid contradicting the now-shipped redesign. If no matches, no change needed.

- [ ] **Step 4: Commit**

```bash
git add docs/visual-identity.md STATUS.md BACKLOG.md
git commit -m "docs: record Notion-inspired visual identity redesign in tracking docs"
```

---

## Task 15: Full verification pass

**Files:** none (verification only, no code changes expected — if this task finds a regression, fix it in the relevant file and note the fix in this task's own commit).

- [ ] **Step 1: Run the complete test suite (Laravel + frontend)**

Run: `./scripts/jt.sh test`
Expected: all PHP and frontend tests PASS.

- [ ] **Step 2: Run the design-token guard one final time**

Run: `./scripts/check-design-tokens.sh`
Expected: `✅ All Visual Identity CI Guards PASSED.`

- [ ] **Step 3: Run the Playwright E2E smoke suite**

Run: `./scripts/jt.sh e2e`
Expected: PASS — this exercises real browser login/navigation flows and will catch any theme-related breakage (e.g. an element with `color-scheme` causing an unreadable native form control) that unit tests can't see.

- [ ] **Step 4: Manual spot-check via the dev server**

Run: `./scripts/jt.sh up`, open `http://localhost:8080` (or `${APP_PORT}`).

Verify by hand:
- Page loads in light theme by default when the OS is in light mode (or dark, if OS is dark) — no flash of the wrong theme.
- Toggling the theme button in the sidebar footer flips every surface (canvas, panels, text) and persists across a reload.
- Resizing the browser below ~768px width: sidebar becomes a hidden drawer opened by the hamburger button; a full-screen backdrop appears behind it when open; CommandPalette (`Cmd/Ctrl+K`) and the `/` slash menu both stay within the viewport width.
- Tab through the UI with the keyboard only: every control (including the new theme toggle and hamburger button) shows a visible focus indicator and is reachable in a logical order (shared spec §10).
- Zoom the browser to 200%: no content is clipped or requires horizontal scrolling on the main views.

If any manual check fails, fix the specific file/rule responsible and re-run Steps 1–3 before proceeding.

- [ ] **Step 5: Commit (only if Step 4 required fixes; otherwise this task produces no diff and needs no commit)**

```bash
git add -A
git commit -m "fix: address issues found during Notion redesign verification pass"
```
