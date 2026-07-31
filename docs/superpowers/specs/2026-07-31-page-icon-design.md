# Page Icon (Emoji) — Design

Date: 2026-07-31
Status: Approved for planning
Scope: Presentation + a small backend indexing change. No new API endpoints,
no database migration, no change to the Markdown-on-disk invariant (an
icon is just another front-matter key).

## 1. Purpose

Feature 1 of 5 Notion-parity UX features proposed for Jotter. Notion shows
a small per-page emoji icon in its sidebar tree and at the top of every
page. Jotter has neither today — `NoteTreeNode.vue` shows only a title,
`NoteEditor.vue`'s title bar shows only `note.title || note.path`.

## 2. Non-goals

- No in-app emoji picker grid (categories, search). The user picks an
  emoji via their OS's native picker (Cmd+Ctrl+Space on macOS, Win+. on
  Windows) and pastes it into a plain text input.
- No icon rendering in `SearchResults.vue`, `BacklinksPanel.vue`, or
  `OutgoingLinksPanel.vue` in this pass — only `NoteTreeNode.vue` and
  `NoteEditor.vue`.
- No cover image/banner (that's a separate proposed feature).
- No change to `NoteProperty`'s API shape, `WorkspacePropertyController`,
  or `PropertiesPanel.vue` itself.

## 3. Storage and data flow

An icon is a front-matter key, `icon`, holding a single emoji string
(e.g. `icon: 📄`) — written and read exactly like any other note
property, with one exception: it must **never** become a queryable
`NoteProperty` row, so it never appears in `PropertiesPanel.vue`'s
generic property list. Precedent: `NotePropertyProjector::project()`
(`app/Domain/Vault/NotePropertyProjector.php`) already excludes the
`tags` key from projection for the same reason (tags have their own
first-class system). `icon` gets identical treatment:

```php
foreach ($frontmatter as $key => $value) {
    if ($key === 'tags' || $key === 'icon' || $value === null) {
        continue;
    }
    // ...
}
```

This is the **only backend code change**. Everything else reuses
existing, already-generic machinery:

- **Read (list/tree):** `NoteMeta.frontmatter` (`Record<string, unknown> |
  null`) is already returned by the notes-list endpoint
  (`WorkspaceNoteController`, confirmed at the line assigning
  `'frontmatter' => $note->frontmatter` in the list response) and already
  typed on the frontend (`frontend/src/services/types.ts`). `node.note
  .frontmatter?.icon` is available in `NoteTreeNode.vue` with zero new
  API calls.
- **Read (editor):** `NoteDetail extends NoteMeta`, so
  `note.frontmatter?.icon` is available in `NoteEditor.vue` the same way.
- **Write/delete:** reuse the existing
  `setNoteProperty(workspaceId, noteId, 'icon', value)` /
  `deleteNoteProperty(workspaceId, noteId, 'icon')` functions in
  `frontend/src/services/api.ts` — they already POST/DELETE to
  `/workspaces/{w}/notes/{note}/properties[/{key}]`, which writes an
  arbitrary front-matter key via `MarkdownDocument::compose()`
  (`WorkspacePropertyController::setProperty`/`deleteProperty`) with no
  restriction to a fixed set of property names. No new endpoint.
- **Refresh after write:** mirror the existing pattern in
  `handleAddProperty`/`handleDeleteProperty` (`NoteEditor.vue`) — after
  the API call resolves, `emit('select-note', props.note.id)`, which
  `App.vue`'s `handleSelectNote` already uses to reload the active note
  (and, since the notes list is refetched on the same path, the tree's
  `frontmatter` too).
- **Reindex:** `php artisan vault:reindex` re-runs
  `NoteProjector`/`NotePropertyProjector` from the Markdown files on
  disk — the exclusion applies automatically to existing notes that
  already happen to have an `icon` key in front-matter (unlikely today,
  but no migration or backfill is needed either way).

## 4. Fallback icon

Notes without an `icon` key show a generic document glyph — the same
inline SVG already used for `App.vue`'s empty-state icon (`.empty-icon`,
`viewBox="0 0 24 24"`, the folded-corner document path) and duplicated
at whatever size each surface needs. This is a deliberate choice: the
icon slot is **always rendered** (emoji or fallback SVG), reserving a
fixed width so the title never shifts position when an icon is added or
removed.

## 5. Component changes

### `NoteTreeNode.vue`

In the `.note-item` branch (the `v-else` branch, before `.note-info`),
add an icon element: the frontmatter emoji as plain text if present,
else the fallback SVG (`width="16" height="16"`, `flex-shrink: 0`). Read
directly from `node.note.frontmatter?.icon` — no new prop needed, since
`NoteTreeNode` already receives full `NoteMeta` objects via its
`TreeFile`/`TreeNode` types. **Read-only** in the tree — no click
handler on the icon here; editing only happens in the editor (§5.2),
keeping the tree a pure navigation surface, consistent with how it
already behaves (no other inline-edit affordances exist in
`NoteTreeNode` besides the hover-reveal delete button).

### `NoteEditor.vue`

In `.note-meta-info`, before `.editor-title`:

- A button-like icon element (emoji text or fallback SVG, `24px`,
  minimum 44×44px hit target per the touch-target rule already
  established for the redesign) that is **clickable**.
- Clicking it toggles a local `isEditingIcon` ref, swapping the icon
  display for an inline `<input type="text" />` (autofocus, same
  baseline as the title) bound to a local `iconDraft` ref initialized
  from the current `note.frontmatter?.icon ?? ''`.
- `Enter`: if `iconDraft` is non-empty, call
  `setNoteProperty(props.workspaceId, props.note.id, 'icon',
  iconDraft.trim())`; if empty, call `deleteNoteProperty(...)` instead
  (so clearing the field and pressing Enter removes the icon). Both
  paths then `emit('select-note', props.note.id)` and close the input
  (mirrors `handleAddProperty`/`handleDeleteProperty` exactly).
- `Escape`: close the input, discard `iconDraft`, no API call.
- When an icon is already set and not being edited, a small "×" affordance
  appears on hover of the icon (reusing the hover-reveal opacity pattern
  from `.btn-delete` in `NoteTreeNode.vue`) that calls
  `deleteNoteProperty` directly, without opening the input — a one-click
  clear shortcut.
- `aria-label`: `"Set page icon"` when no icon is set, `"Change page
  icon"` when one is set.

## 6. Testing

There is **no existing spec that exercises `setNoteProperty`/
`deleteNoteProperty`** today (`handleAddProperty`/`handleDeleteProperty`
in `NoteEditor.vue` are implemented but not covered by
`frontend/src/App.spec.ts`'s current test list) — this feature adds that
coverage from scratch rather than extending an established pattern:

- `App.spec.ts`'s `vi.mock('./services/api', ...)` gains
  `setNoteProperty` and `deleteNoteProperty` mock entries (both resolving
  to a `NoteDetail`-shaped object).
- New test: a note with `frontmatter: { icon: '📄' }` renders that emoji
  in both the tree row and the editor title icon slot.
- New test: a note with no `icon` key renders the fallback SVG in both
  places (structural check — presence of the fallback icon element,
  absence of emoji text).
- New test: clicking the editor's icon, typing an emoji, pressing Enter
  calls `setNoteProperty` with `('icon', <the typed value>)` and closes
  the input.
- New test: pressing Enter with an empty draft (or clicking the hover "×")
  calls `deleteNoteProperty(..., 'icon')`.
- New test: pressing Escape closes the input without calling either API
  function.

No new PHP test is strictly required for the projector change (a single
added `||` condition mirroring `tags`), but
`tests/Feature/NotePropertyProjectionTest.php` (confirmed to exist,
covers `tags`' exclusion today) gets one new assertion: a note with an
`icon` front-matter key produces zero matching `NoteProperty` rows,
exactly mirroring its existing `tags` assertion.

## 7. Out of scope / open questions carried forward

- No in-app emoji picker (§2) — if requested later, it's a separate,
  larger design (component, emoji dataset, search/categories).
- No rendering in `SearchResults`/`BacklinksPanel`/`OutgoingLinksPanel`
  (§2) — a natural follow-up once this ships, not bundled in.
- No cover image feature (proposed separately, not part of this spec).
