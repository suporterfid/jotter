# Inline "+" Per-Folder Quick Create — Design

Date: 2026-07-31
Status: Approved for planning
Scope: Presentation-only. No new API endpoints, no database change, no
change to the Markdown-on-disk invariant — reuses the existing note-create
endpoint and `App.vue`'s existing `handleCreateNote` handler verbatim.

## 1. Purpose

Feature 3 of 5 Notion-parity UX features proposed for Jotter (features 1,
page icon, and 2, sidebar drag-and-drop, are already implemented and
merged). Notion shows a "+" on hover over a page-tree folder to instantly
create a new page inside it. Jotter today only offers note creation via
the header "New Note" modal (typed full path) or the Command Palette's
quick-create (always at the vault root) — there is no way to create a
note directly inside a specific folder without typing that folder's path
by hand into the modal.

## 2. Scope

**In scope:**
- A hover-reveal "+" button on each **folder** row in `NoteTreeNode.vue`,
  same visual/interaction pattern as the existing hover-reveal
  `.btn-delete` on note rows.
- Clicking it creates a new note directly inside that folder, auto-selects
  it in the editor, and auto-expands the folder if it was collapsed.

**Out of scope (confirmed during brainstorming):**
- No "+" on note rows — creating a sibling note is already covered by the
  parent folder's own "+", so a second affordance per note row would be
  redundant.
- No "+" on the root-level "All Notes" section header — the vault root
  already has two quick-create paths (header "New Note" modal, Command
  Palette), a third would be redundant.
- No inline rename prompt after creation. Jotter has no separate editable
  "title" field — `NoteEditor.vue`'s title (`.editor-title`, confirmed
  read-only `<h2>`) is derived from the note's first `# heading` or its
  path. Renaming already happens by editing that heading in the body, same
  as every other note creation path in the app.
- No filename collision handling beyond what already exists: the
  auto-generated name uses the same `untitled-<base36 timestamp>.md`
  pattern as `CommandPalette`'s quick-create (`App.vue`'s
  `@create-note="() => handleCreateNote(...)"` handler), which is already
  accepted as sufficiently collision-free for this app's usage pattern.

## 3. Component changes

### `NoteTreeNode.vue`

In the folder branch's `.folder-row` button, next to the existing
`.folder-count` badge, add a "+" button:
- Hover-reveal opacity (same pattern as `.btn-delete`), full 44×44px hit
  target under the existing `@media (max-width: 768px)` touch rule so it's
  always visible (not hover-dependent) on touch devices.
- `@click.stop` — critical: `.folder-row`'s own click handler toggles
  `expanded`, and the "+" sits inside that same button-like row, so its
  click must not also trigger the parent's toggle in a way that conflicts
  with the auto-expand behavior below (only this button's own logic
  should decide expansion state on this interaction).
- `title="Create note in this folder"` / `aria-label` for accessibility.
- Handler:
  ```typescript
  function createNoteInFolder() {
    expanded.value = true
    emit('create-note-in-folder', (props.node as TreeFolder).fullPath)
  }
  ```
- New emit added to the existing `defineEmits`:
  `(e: 'create-note-in-folder', folderPath: string): void`
- The recursive `<NoteTreeNode>` invocation (folder's own children list)
  gets `@create-note-in-folder="$emit('create-note-in-folder', $event)"`
  added alongside the existing `@select-note`/`@delete-note` bubble-up,
  so the event propagates from any depth up to `Sidebar.vue`.

### `Sidebar.vue`

- New emit: `(e: 'create-note-in-folder', folderPath: string): void`.
- The root-level `<NoteTreeNode>` invocation (the `v-for="node in
  noteTree"` one, depth 0) gets
  `@create-note-in-folder="$emit('create-note-in-folder', $event)"`.

### `App.vue`

New handler, placed next to the existing `handleCreateNote`:
```typescript
function handleCreateNoteInFolder(folderPath: string) {
  const fileName = `untitled-${Date.now().toString(36)}.md`
  const path = folderPath === '' ? fileName : `${folderPath}/${fileName}`
  handleCreateNote(path)
}
```
Wired on `<Sidebar>` as `@create-note-in-folder="handleCreateNoteInFolder"`.
Reuses `handleCreateNote` unchanged — it already does the create-API-call
→ `refreshNotesList()` → `handleSelectNote(created.id)` sequence, so no
new API-calling code is written; this handler is purely a path-prefixing
adapter in front of it. `folderPath === ''` case exists for defensive
completeness (matches the fallback root-folder path used throughout
`buildTree()`) even though the root header has no "+" per §2 — the root
folder node itself is never rendered as a `.folder-row` today, so this
branch is not reachable through the UI, only through direct calls to
`handleCreateNoteInFolder('')`.

## 4. Testing

**Frontend (Vitest), `NoteTreeNode.spec.ts`:**
- Clicking the "+" on a folder row emits `create-note-in-folder` with that
  folder's exact `fullPath`.
- Clicking the "+" on a folder row does **not** toggle `expanded` away
  from its already-expanded state (i.e., it doesn't collapse an open
  folder) — asserts `.folder-children`'s `display` stays visible
  afterward.
- Clicking the "+" on a **collapsed** folder row both expands it
  (`.folder-children` becomes visible) and emits the event.
- A nested folder's "+" click bubbles the event up through an intermediate
  parent `NoteTreeNode` unchanged (asserts the emitted `folderPath` is the
  nested folder's path, not the parent's).

**`Sidebar.spec.ts`:**
- Clicking a folder's "+" in a mounted `Sidebar` emits
  `create-note-in-folder` from the `Sidebar` component itself with the
  correct path (confirms the root-level wiring, not just `NoteTreeNode`'s
  own emit).

**`App.spec.ts`:**
- `handleCreateNoteInFolder('docs')` calls the (mocked) `createNote` API
  function with path `docs/untitled-<something>.md` (asserted via a
  regex/prefix match, since the timestamp portion is non-deterministic).
- `handleCreateNoteInFolder('')` calls `createNote` with a bare
  `untitled-<something>.md` path (no leading slash or empty segment).

No backend test changes — no backend code changes in this feature.

## 5. Out of scope / open questions carried forward

- No "+" on note rows or the root header (§2).
- No inline rename UX (§2) — would require Jotter to grow an editable
  title field, a larger, separate feature if ever requested.
- Filename collision at sub-millisecond double-click speed is
  theoretically possible (same as the pre-existing Command Palette
  quick-create) but not handled — consistent with existing accepted risk,
  not a new gap introduced by this feature.
