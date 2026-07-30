# Notion-Inspired Visual Identity Redesign

Date: 2026-07-30
Status: Approved for planning
Scope: Presentation layer only. No API contract changes, no change to the
Markdown-on-disk invariant, no change to §8 security requirements of
`docs/visual-identity.md`.

## 1. Purpose

Jotter's current theme (dark-only, near-black canvas, purple `#814DDE`
accent, Open Sans) fully implements the shared visual identity spec but
reads as its own distinct look. This redesign replans Jotter's extension
of that spec to visually resemble Notion: light-first with full dark-mode
support, neutral-first accent color, Inter typeface, and minimal
elevation (borders over shadows, tint over cards).

This document is a **Jotter Extension** to `docs/visual-identity.md` — it
supersedes the current "Jotter Extensions" section of that file once
implemented, and updates the deviation log per §14.

## 2. Non-goals

- No change to the Markdown-on-disk data model or API contracts.
- No change to §8 (Content-Security-Policy, self-hosted-only assets) —
  Inter ships self-hosted exactly like Open Sans does today.
- No change to the project mark (`assets/brand/mark.svg`,
  `wordmark.svg`, `favicon.svg`) — these keep `#814DDE` unchanged; the
  mark is explicitly out of scope per shared spec §8.
- Not a rewrite of component logic/data flow — interaction changes below
  are visual/state-presentation changes to existing components, not new
  features.

## 3. Theme mechanism (light + dark, user-toggleable)

- New `data-theme="light"|"dark"` attribute on `<html>`.
- Resolution order on load: `localStorage.theme` (explicit user choice)
  → `matchMedia('(prefers-color-scheme: dark)')` → default `light`.
- An inline, blocking script in `index.html` sets the attribute before
  first paint (avoids flash of wrong theme). This is the one exception
  to "no inline script" territory this project needs to reason about —
  it sets an attribute only, no logic beyond reading `localStorage`/
  `matchMedia`, and ships same-origin (no CSP `script-src` change: it's
  inline but the CSP today already requires reasoning about this — see
  Open Question in §9).
- Manual toggle control lives in the Sidebar footer (desktop) / inside
  the mobile drawer (mobile, see §7). Toggling writes `localStorage.theme`
  and flips the `data-theme` attribute; no reload required.
- `tokens.css` replaces its single dark-only `:root` block with two
  blocks: `:root[data-theme="light"]` and `:root[data-theme="dark"]`,
  each setting `color-scheme` to match (so native form controls and
  scrollbars follow).

## 4. Palette — neutral-first, Notion-style

| Token | Light | Dark |
|---|---|---|
| `--color-canvas` | `#FFFFFF` | `#191919` |
| `--color-surface` | `#F7F6F3` | `#202020` |
| `--color-surface-emphasis` | `#EDECE9` | `#2F2F2F` |
| `--color-text` | `#37352F` | `#D4D4D4` |
| `--color-text-muted` | `#787774` | `#9B9B9B` |
| `--color-text-inverse` | `#FFFFFF` | `#191919` |
| `--color-border` | `rgb(55 53 47 / 9%)` | `rgb(255 255 255 / 9%)` |
| `--color-border-strong` | `rgb(55 53 47 / 16%)` | `rgb(255 255 255 / 16%)` |
| `--color-action` | `--color-text` (near-black) | `--color-text` (near-white) |
| `--color-action-hover` | `#000000` | `#FFFFFF` |
| `--color-focus` | `#37352F` | `#D4D4D4` |
| `--color-hover` (new) | `rgb(55 53 47 / 6%)` | `rgb(255 255 255 / 6%)` |

`#814DDE` purple is **removed from all functional/semantic tokens**. It
survives only inside `assets/brand/mark.svg`, `wordmark.svg`, and
`favicon.svg`, unchanged, per shared spec §8 ("project mark is visually
separate from this shared system"). This is a §14 deviation from the
shared spec's baseline purple accent — logged in §8 of this document.

`--color-hover` is a new token, additive per §14 rule 1 (`--color-*`
naming preserved). It is distinct from `--color-surface-emphasis`:
`surface-emphasis` remains reserved for panels/callouts that need to
stand out; `hover` is for transient row/item hover state in lists, trees,
and menus (§6).

### 4.1 Status colors — re-verification required

Status tokens (`--color-status-danger/warning/success/info`) keep their
semantic role but need **new values verified per theme**. Because the
project now has two canvases and two surfaces, the appendix contrast
table in `docs/visual-identity.md` doubles: 4 tokens × 2 backgrounds ×
2 themes = 16 verified pairs (today: 8, dark-only). Exact hex values and
measured ratios are an implementation-time task (§9), not fixed here —
each must independently clear 4.5:1 against both `--color-canvas` and
`--color-surface` in both `data-theme` blocks before merge.

### 4.2 Baseline text-pair verification

The shared spec's §3.4 baseline table (`--color-text`/`--color-text-muted`
against canvas/surface) currently only has dark-mode numbers recorded in
Jotter's appendix. Light-mode pairs must be measured and recorded
alongside them using the hex values in §4 above.

## 5. Typography

Open Sans → **Inter**, replacing the self-hosted font entirely:

- Same pipeline as the existing VI4 process: `fonttools varLib.instancer`
  to produce static weight-pinned instances (400/500/600/700), then
  `fonttools subset` with the same Unicode ranges Jotter's copy uses.
- SIL OFL 1.1 license (Inter's own upstream license) copied verbatim to
  `public_html/assets/fonts/LICENSE.txt`, replacing the Open Sans one.
- No CDN, no variable-font `wght` range — same constraint as today,
  same reasoning (static instances, declaring a range they can't satisfy
  would violate spec §4.1).
- `--font-sans` fallback chain updates to `"Inter", -apple-system,
  BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, Cantarell,
  "Helvetica Neue", Arial, sans-serif` — same length/fallback philosophy,
  not shortened.
- Type scale (`--text-*` tokens) and weight-discipline rule (§4.4, max 3
  weights per view) are unchanged — this is a typeface swap, not a scale
  redesign.

## 6. Spacing, radius, elevation

- **Spacing**: unchanged. The existing 8px scale (`--space-1`…`--space-24`)
  already fits Notion's rhythm; no redesign needed.
- **Radius**: shrinks across the board — `--radius-sm` 6px→3px,
  `--radius-md` 8px→6px, `--radius-lg` 12px→8px. `--radius-pill` unchanged
  (still used for tags/badges).
- **Elevation**: `--shadow-lg` is renamed `--shadow-float` and its use is
  restricted to floating/overlay elements only — modals, CommandPalette,
  SlashMenu, popovers (§6.3). Everywhere else that currently uses a
  shadow (cards, panels) drops it entirely: emphasis is communicated with
  `--color-border` + `--color-surface-emphasis`/`--color-hover`, not
  drop shadow. This is a §14 deviation from the shared spec's existing
  "elevation via surface layering, not shadow" guidance — Jotter already
  followed that guidance for panels, but had one exception (`--shadow-lg`
  used more broadly); this redesign tightens it to floating-only.

## 7. Component interaction changes

### 7.1 Sidebar / NoteTreeNode

Row action icons (add child, `...` menu) move from always-visible to
`opacity: 0` at rest → `opacity: 1` on `:hover`/`:focus-within` on the row
— matches Notion's page tree. Row hover background uses the new
`--color-hover` token. Collapse chevron gets a rotate transition using
existing `--duration-fast`/`--ease-standard` tokens (already covered by
the global `prefers-reduced-motion` block in `tokens.css`).

**Mobile exception** (§7.5): hover-reveal doesn't work on touch. Below
the mobile breakpoint, action icons are always visible in the row — no
hidden-until-hover state on touch devices.

### 7.2 NoteEditor

Drops outer panel border/shadow — the editor becomes a borderless,
canvas-colored area rather than a bordered card. Title renders at
`--text-h1`/`--text-display` scale at the top of a `.prose` reading
column (640–760px per shared spec §5), matching a Notion page rather
than a form panel.

### 7.3 CommandPalette + SlashMenu

Compact floating popover: `--radius-md`, `--color-border`, and
`--shadow-float` (the one explicit exception to the no-shadow-on-panels
rule in §6 — these are the reserved floating-element case). Row hover
uses `--color-hover`, no border. Icon/label/optional-shortcut-hint
alignment cleaned up to match Notion's `/` menu layout.

### 7.4 Side panels

Backlinks, Comments, History, Properties, OutgoingLinks,
UnlinkedMentions, Attachments, LinkReport, and AuditLog panels (9
components) are consolidated onto one shared panel presentation pattern:
slide-in from the right on desktop, `--color-border` left divider only
(no shadow, no surface-emphasis card), consistent header row (title +
close/collapse icon top-right), consistent empty-state and section
spacing. Implementation likely extracts a shared wrapper (e.g.
`PanelShell.vue`) or shared CSS class — left to the implementation plan,
not fixed here, since today each of the 9 components rolls its own
header/close affordance independently.

### 7.5 Responsiveness (mobile)

Shared spec §10 floor (320px no horizontal scroll, 200% zoom) is
unchanged and still applies. Breakpoint for the behavior changes below:
proposed `768px`, to be confirmed against the frontend's existing
breakpoint usage at implementation time (avoid introducing a second,
inconsistent breakpoint value if one is already established).

- **Sidebar**: becomes an off-canvas drawer below the breakpoint (hidden
  by default, opened via a hamburger control/gesture) instead of a fixed
  column — a fixed sidebar column is not viable at 320–375px alongside
  the reading column.
- **Side panels** (§7.4): become a bottom sheet or full-screen overlay on
  mobile rather than a ~320–400px slide-in panel, which does not fit
  next to the editor on a narrow viewport.
- **CommandPalette/SlashMenu**: width becomes relative (e.g. `90vw`) below
  the breakpoint instead of a fixed pixel width, with minimum lateral
  padding preserved.
- **NoteEditor**: already responsive via the `.prose` column and the
  existing `clamp()`-based `--text-h1`/`--text-h2` tokens — verify the
  large title does not break at 320px during implementation.
- **Touch targets**: the existing 44×44px minimum (shared spec §7,
  buttons) extends explicitly to the Sidebar's hover-reveal icons when
  they render always-visible on touch (§7.1) — no icon smaller than
  44×44px is a tap target.
- **Theme toggle**: lives inside the mobile Sidebar drawer rather than a
  separate fixed control, since there is no room for an extra persistent
  bar on small viewports.

## 8. Deviation log additions (§14 of shared spec)

Three new entries for Jotter's deviation log, alongside the four already
recorded:

| Deviation | Reason |
|---|---|
| `--color-action`/`--color-focus`/link color are neutral (near-black light / near-white dark) rather than the shared spec's purple baseline | Notion-parity redesign: color is reserved for the project mark only; functional UI uses a neutral-first palette matching Notion's actual visual language. |
| Typeface changed from Open Sans to Inter | Visual alignment with the Notion-inspired redesign; self-hosting/no-CDN/subsetting policy (VI4) is preserved unchanged, only the source font differs. |
| `--shadow-float` (formerly `--shadow-lg`) is reserved exclusively for floating/overlay elements (modals, CommandPalette, SlashMenu, popovers); all other surfaces (cards, panels) use border + tint only | Notion's elevation language is almost entirely border/tint-based; this tightens Jotter's existing "layering over shadow" guidance from mostly-followed to strictly-enforced. |

## 9. Verification, CI, and process impact

- **Contrast**: appendix table in `docs/visual-identity.md` gains 16
  status-color pairs (was 8) plus light-mode baseline text pairs (§4.1,
  §4.2). Exact final hex values and measured ratios are determined during
  implementation, not fixed by this design doc — this doc fixes the
  *structure and neutral-color direction*, not final signed-off hex
  values for status colors.
- **axe-core (#109)**: existing structural specs (`frontend/src/a11y.spec.ts`,
  9 views) are theme-independent (ARIA, labels, headings) and need no
  duplication per theme. Real color-contrast verification remains a
  manual/E2E check against the doubled contrast table above, same
  process as today.
- **CI guard (`scripts/check-design-tokens.sh`, #110)** needs three
  updates:
  1. Palette guard already rejects `--color-purple-*` in components;
     confirm it still does now that purple has zero functional callers
     (i.e. the guard shouldn't need loosening — components simply won't
     reference it anymore).
  2. External-font-CDN guard is unchanged in behavior, just now protects
     Inter instead of Open Sans.
  3. New check: both `data-theme` blocks in `tokens.css` define every
     token listed in shared spec §11 — a token present in one theme
     block and missing in the other is a silent breakage (falls back to
     `initial`/inherited value) and should fail CI, not just visually
     regress.
- **Out of scope confirmation**: no API contract change, no Markdown-on-
  disk invariant change, no §8 security-requirement change — consistent
  with the original epic #96 scope note already in
  `docs/visual-identity.md`.

## 10. Open questions for implementation

- Exact mobile breakpoint value (§7.5) — confirm against existing
  frontend CSS rather than introducing a new value.
- Whether the theme-detection inline script needs a CSP nonce/hash, or
  whether the existing `script-src 'self'` policy already permits it
  as a `<script>` tag with no `src` (needs a decision recorded against
  the Content-Security-Policy section of `docs/visual-identity.md`
  during implementation — this doc surfaces the question, does not
  resolve it).
- Final signed-off hex values and measured contrast ratios for the 4
  status tokens across both themes (§4.1).
- Shared wrapper/component structure for the 9 consolidated side panels
  (§7.4) — left to the implementation plan.
