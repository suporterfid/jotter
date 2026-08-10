# Published static-site font assets — design

## Goal

Make every newly published Jotter static site render the product's UI, editorial, and code typography without relying on fonts installed on a visitor's device. The scope is limited to static workspace publications.

## Context

The publication stylesheet declares the `Inter`, `Source Serif 4`, and `IBM Plex Mono` font stacks. The publish controller currently copies only the Inter WOFF2 assets, so headings and code blocks fall back to system fonts even when the publisher has a complete local application build.

## Decision

Store the licensed WOFF2 subsets required for publication alongside the existing self-hosted Inter files in `frontend/src/assets/fonts`. The published stylesheet will provide explicit `@font-face` declarations for Source Serif 4 and IBM Plex Mono, and the publish controller will copy a defined set of font assets into each generated site's `fonts` directory.

The source asset list is intentionally explicit rather than a wildcard contract. It makes the published asset surface reviewable and lets the feature test verify every required output.

## Failure and fallback behavior

Copying a missing source font is non-fatal. The controller will continue publishing the site, leaving the relevant `@font-face` resource unavailable. Because every CSS stack already ends with appropriate system and Noto fallbacks, the visitor still receives a readable editorial or monospaced rendering.

This is a best-effort publishing behavior, not a network fetch: the publish path will not download external resources, depend on a local browser, or introduce Notion assets.

## Components and data flow

1. Versioned font assets live under `frontend/src/assets/fonts` with their license material.
2. `resources/views/publish/publish.css` maps the Source Serif 4 and IBM Plex Mono WOFF2 files to their declared family names and uses `font-display: swap`.
3. `WorkspacePublishController` copies the stylesheet, script, and required font filenames into `storage/app/public/sites/<workspace>/fonts`.
4. Published HTML keeps referring to the cache-busted static stylesheet path already used by the publication template; no runtime API or external font request is introduced.

## Testing

Extend `WorkspacePublishTest` to assert that publishing creates all required font files and that the copied stylesheet contains matching Source Serif 4 and IBM Plex Mono `@font-face` declarations. Add a focused fallback test by making one configured source unavailable in the test fixture and asserting that publishing still returns success and writes the page and remaining assets.

The existing publication tests remain responsible for light/dark behavior and static page generation. The full PHP suite validates that the new font behavior does not affect the other publication paths.

## Non-goals

- Marketing pages, logo work, or assets owned by Notion.
- Runtime font downloads or CDN dependencies.
- Adding arbitrary font weights beyond the weights actually used by the static stylesheet.
- Changing the identity token values or theme preference behavior.

## Self-review

No placeholders remain. The explicit source-list design, non-fatal missing-file behavior, CSS fallback stacks, and test expectations agree with the approved scope.
