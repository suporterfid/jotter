# Jotter Brand Assets & Visual Identity Specification

This directory contains the official brand assets for Jotter.

## Asset Inventory

- `mark.svg`: Primary brand mark. Carries its own embedded dark (`#000000`) backdrop and `#814DDE` purple fill baked into the file — it does not adapt to the app's light/dark theme toggle, by design (see Approved Backgrounds below).
- `mark-monochrome.svg`: Single-color fallback mark.
- `wordmark.svg`: Official wordmark incorporating the brand title and mark.
- `favicon.svg`: Vector favicon optimized for 16×16px to 32×32px rendering.
- `social-card.png`: Open Graph and Twitter summary image (1200×630px).

## Usage Guidelines

### Clear Space
Maintain clear space around the mark equal to at least 50% of its width/height (`0.5x`). No text, borders, or competing elements should encroach upon this zone.

### Minimum Sizes
- **Mark**: Minimum rendered size 16×16px (favicon scale).
- **Wordmark**: Minimum rendered height 24px.

### Approved Backgrounds
- **Primary Mark (`mark.svg`)**: Self-contained — it ships with its own `#000000` backdrop rect baked into the file, so it stays legible on any page background regardless of Jotter's light/dark theme (`docs/visual-identity.md` §2–3). Do not place it directly on a light `--color-canvas`/`--color-surface` expecting it to blend in; it is meant to read as a fixed dark badge, not a theme-aware element.
- **Monochrome Mark (`mark-monochrome.svg`)**: Uses `fill="currentColor"` — the caller sets one ink color via CSS `color`. Approved for both light and high-contrast dark backgrounds, since it has no embedded backdrop of its own.

### Prohibited Treatments
- Do not stretch, warp, or distort the aspect ratio.
- Do not apply unapproved drop shadows, glows, or gradients to the vector geometry.
- Do not alter the mark's purple fill (`#814DDE`) or its embedded `#000000` backdrop — these are fixed, independent of `docs/visual-identity.md`'s theme tokens (which no longer use purple or this specific black at all; see §3 there).

## License & Ownership

All brand assets (`mark.svg`, `mark-monochrome.svg`, `wordmark.svg`, `favicon.svg`, `social-card.png`) are copyright © Jotter Contributors. They are licensed under the MIT License along with the project codebase.
