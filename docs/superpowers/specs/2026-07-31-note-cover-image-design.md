# Note Cover Image — Design

Date: 2026-07-31
Status: Approved for planning
Scope: Small backend indexing change (mirrors feature 1's `icon` exclusion)
plus frontend. No new upload endpoint — reuses the existing attachment
upload flow. No change to the Markdown-on-disk invariant (a cover is just
another front-matter key).

## 1. Purpose

Feature 5 of 5 Notion-parity UX features proposed for Jotter — the last
one (features 1, page icon; 2, sidebar drag-and-drop; 3, inline
per-folder quick-create; 4, editor breadcrumb trail are already
implemented and merged). Notion lets a page have a banner "cover image"
above its title. Jotter has neither today.

## 2. Non-goals

- No image cropping/repositioning UI (Notion's drag-to-reposition-within-
  the-frame). The image is shown with `object-fit: cover` at a fixed
  banner height — good enough framing without a repositioning feature.
- No gallery/preset cover images (Notion's built-in art library). Only
  user-supplied upload or URL.
- No change to the page icon (feature 1) — it keeps its current position
  in the title row, unrelated to and not overlapping the cover banner.
  Keeping the two features decoupled avoids coupling this change to
  feature 1's code.
- No new attachment/upload backend code — reuses the existing
  `POST /workspaces/{workspace}/attachments` endpoint
  (`AttachmentController::store`, confirmed already present) as-is.

## 3. Storage and data flow

A cover is a front-matter key, `cover`, holding a single URL string
(e.g. `cover: https://.../banner.jpg` or a same-origin attachment URL
like `/api/workspaces/2/attachments/covers/banner.jpg`) — written and
read exactly like any other note property, with the same one exception
`icon` already has: it must never become a queryable `NoteProperty` row.
Precedent: `NotePropertyProjector::project()`
(`app/Domain/Vault/NotePropertyProjector.php:16`) already excludes both
`tags` and `icon` for this reason. `cover` gets identical treatment:

```php
if ($key === 'tags' || $key === 'icon' || $key === 'cover' || $value === null) {
    continue;
}
```

This is the **only backend code change**. Everything else reuses
existing, already-generic machinery:

- **Read:** `note.frontmatter?.cover` is available in `NoteEditor.vue`
  the same way `note.frontmatter?.icon` already is (both `NoteMeta` and
  `NoteDetail` already type `frontmatter` as `Record<string, unknown> |
  null`, confirmed in `frontend/src/services/types.ts`).
- **Write (upload path):** call the existing
  `uploadAttachment(workspaceId, file)` (`frontend/src/services/api.ts:312`,
  confirmed already present, POSTs to
  `/workspaces/{w}/attachments` and returns an `AttachmentItem` with a
  `url` field), then call the existing
  `setNoteProperty(workspaceId, noteId, 'cover', attachment.url)`.
- **Write (URL path):** call `setNoteProperty(workspaceId, noteId,
  'cover', pastedUrl)` directly — no upload call at all.
- **Delete:** the existing `deleteNoteProperty(workspaceId, noteId,
  'cover')`.
- **Refresh after write:** mirror the existing pattern from feature 1's
  icon handlers (`NoteEditor.vue`) — after the API call resolves, emit
  `select-note` so `App.vue`'s existing reload path picks up the new
  `frontmatter`.
- **Reindex:** `php artisan vault:reindex` re-runs
  `NotePropertyProjector` from the Markdown files on disk — the exclusion
  applies automatically, no migration or backfill needed.

## 4. Component changes

### New: `CoverImageModal.vue`

A small standalone modal, structurally similar to the app's existing
modal patterns (e.g. the "New Note" modal in `Sidebar.vue`: a
`.modal-overlay` with `@click.self` to dismiss, a `.modal-card`). Two
tabs:
- **Upload tab:** a file `<input type="file" accept="image/*">`. On
  file selection, immediately calls `uploadAttachment` then emits
  `set-cover` with the resulting URL, closing the modal.
- **URL tab:** a text `<input type="url">` plus a "Set cover" button
  that emits `set-cover` with the typed value, closing the modal.

Props: `workspaceId: number`. Emits: `(e: 'set-cover', url: string):
void`, `(e: 'close'): void`.

### `NoteEditor.vue`

Above the existing `<header class="editor-bar">`:
- **No cover set** (`note.frontmatter?.cover` is absent/not a non-empty
  string): a thin, full-width "Add cover" bar/button
  (`data-testid="add-cover-btn"`), always visible (not hover-only —
  matches this app's existing discoverability choice from feature 1,
  where the icon's fallback glyph is always shown rather than hidden
  behind hover).
- **Cover set:** an `<img class="editor-cover" :src="coverUrl">`,
  fixed height (`200px`), `width: 100%`, `object-fit: cover`. On hover,
  two buttons fade in over the image: "Change" (reopens
  `CoverImageModal`) and "Remove" (calls `deleteNoteProperty` directly,
  mirroring the icon's existing hover-`×` clear affordance).
- Clicking "Add cover" or "Change" opens `CoverImageModal` bound to the
  current `workspaceId`; its `set-cover` emit triggers
  `setNoteProperty(workspaceId, note.id, 'cover', url)` then the same
  `select-note` re-emit refresh pattern used elsewhere in this file.

## 5. Testing

**Frontend (Vitest):**
- A note with `frontmatter: { cover: 'https://x/y.jpg' }` renders
  `.editor-cover` with that `src`, and no "Add cover" button.
- A note with no `cover` key renders the "Add cover" button, no
  `.editor-cover` image.
- Clicking "Add cover" then completing the modal's upload tab (mocked
  `uploadAttachment`) calls `setNoteProperty` with `('cover', <the
  mocked attachment url>)`.
- Clicking "Add cover" then completing the modal's URL tab calls
  `setNoteProperty` with `('cover', <the typed url>)` and does **not**
  call `uploadAttachment`.
- Clicking "Remove" on a note with a cover calls `deleteNoteProperty(...,
  'cover')`.
- `CoverImageModal.vue` gets its own focused spec: switching tabs shows
  the right input, the URL tab's button is disabled for an empty input,
  `@click.self` on the overlay emits `close`.

**Backend (PHPUnit):**
- `tests/Feature/NotePropertyProjectionTest.php` (confirmed to exist,
  already covers `tags`'/`icon`'s exclusion) gets one new assertion: a
  note with a `cover` front-matter key produces zero matching
  `NoteProperty` rows, mirroring the existing `icon` assertion exactly.

## 6. Out of scope / open questions carried forward

- No cover repositioning/cropping UI (§2).
- No built-in cover art gallery (§2).
- No icon/cover visual coupling (e.g. icon overlapping the cover's
  bottom edge, as Notion does) — the two stay visually independent (§2).
