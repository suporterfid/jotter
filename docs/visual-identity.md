# Jotter visual identity

This is the implementation contract for Jotter's digital product UI and its static publishing pages. It follows the editorial, content-first character of the canonical identity specification without copying Notion assets, copy, icons, or trademarks.

## Principles

- Use semantic tokens only; components do not declare color literals.
- Keep reading surfaces calm, dense enough for work, and centered at a 720 px maximum measure.
- Support light, dark, and system preference from the first paint.
- Treat internationalization, RTL, keyboard access, zoom, and reduced motion as structural requirements.

## Theme and tokens

`frontend/src/styles/tokens.css` is the source of truth. Every `--color-*` token has matching light and dark values. Key foundations are:

| Token | Light | Dark |
|---|---:|---:|
| `--color-bg-canvas` | `#FFFFFF` | `#191919` |
| `--color-bg-surface` | `#F7F6F3` | `#202020` |
| `--color-bg-elevated` | `#FFFFFF` | `#252525` |
| `--color-text-primary` | `#252525` | `#F1F1EF` |
| `--color-action-primary` | `#1A6DC1` | `#529CCA` |
| `--color-focus-ring` | `#1A6DC1` | `#79B8E8` |

Status messages use their paired `*-fg`, `*-bg`, and `*-border` tokens. A status also requires text or another non-color cue. Primary action content uses `--color-action-primary-content`.

The user preference is persisted under `jotter-theme` as `system`, `light`, or `dark`. `system` follows `prefers-color-scheme`; both SPA shells and static pages resolve it before first paint. `color-scheme`, `prefers-reduced-motion`, and `forced-colors` are supported.

## Typography and layout

- UI/body: Inter with Noto script fallbacks.
- Editorial accent: Source Serif 4 with Noto Serif fallbacks.
- Code/data: IBM Plex Mono with Noto Sans Mono fallbacks.
- Font weights: 400, 500, 600, 700 only; assets are self-hosted and use `font-display: swap`.
- Use the canonical 4 px spacing scale, logical CSS properties, 2/4/6/8 px radii, and three reserved elevation levels.
- Controls preserve a 44 by 44 CSS px target. Focus is `2px solid var(--color-focus-ring)` with a 2 px offset.

The app provides a persistent/collapsible sidebar on larger screens and an overlay sidebar on small screens. Tables scroll within their own region. Actions wrap rather than clipping. Public pages and the editor use the 720 px reading measure; data views may expand when needed.

## Internationalization and RTL

All interface copy uses i18n message keys. Locale changes synchronize `html[lang]`; Arabic and Hebrew locales set `html[dir="rtl"]`. New layout code must use logical inline/block properties and mirror only directional icons. Allow at least 30% text expansion (2x for short controls), locale-aware pluralization, dates, numbers, sorting, and autonym language names. Do not apply Latin letter-spacing or per-character wrappers to Arabic, Hebrew, CJK, Thai, or Devanagari content.

## Public publishing

`resources/views/publish/page.blade.php` and `publish.css` form an offline static contract. Generated pages carry theme bootstrap, canonical theme tokens, keyboard focus, reduced motion, forced-colors behavior, and self-hosted Inter. No public page may reintroduce the legacy black/purple/Open Sans surface.

## Governance and verification

Run `bash scripts/check-design-tokens.sh` after styling changes. It enforces no raw component color literals, no palette-token leakage, no external font CDN, visible focus, and light/dark token parity. Add a token to both themes and its intended foreground pairing before use.

Before release, verify light/dark/system, 200% zoom, keyboard focus, forced colors, reduced motion, long labels, mixed direction text, CJK wrapping, pseudo-locales `en-XA` and `ar-XB`, static published pages, and browser-based accessibility checks.
