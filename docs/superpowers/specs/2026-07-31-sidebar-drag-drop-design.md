# Sidebar Drag-and-Drop (Reorder + Reparent) — Design

Date: 2026-07-31
Status: Approved for planning
Scope: New backend migration + endpoint, new frontend dependency (SortableJS),
changes to `NoteTreeNode.vue`/`Sidebar.vue`. Deliberately outside the
Markdown-on-disk invariant — see §6.

## 1. Purpose

Feature 2 of 5 Notion-parity UX features proposed for Jotter. Today
`NoteTreeNode.vue`/`Sidebar.vue` has zero drag-and-drop code — the sidebar
tree is built entirely by `buildTree()` (`Sidebar.vue:487`) from each
note's `path`, sorted alphabetically (folders) or by the active sort mode
(`recent`/`name`/`path`, files). This feature adds Notion-style
click-and-drag to reorder items and move notes between folders.

## 2. Scope

**In scope:**
- Dragging a note to reorder it among siblings, or into a different
  folder (reparenting — renames the note's path).
- Dragging a folder to reorder it among sibling folders at the same
  level.
- A new `manual` sort mode; drag is only active when it's selected.
- Desktop and touch support.

**Out of scope (explicit, confirmed during brainstorming):**
- Dragging a folder into a different parent folder (subtree reparenting).
  Folders only reorder among siblings — never change parent. Reparenting
  a folder would require bulk-renaming every note under it and rewriting
  wikilinks for each one in a single operation; not attempted here.
- Undo for a drag mistake (the user can always drag again, or edit the
  note's path manually via existing rename).
- Any change to `NoteProperty`, front-matter, or `vault:reindex`.

## 3. Data model

**Migration — `notes` table:**
```php
$table->integer('sort_position')->nullable();
```

**Migration — new `folder_positions` table:**
```php
Schema::create('folder_positions', function (Blueprint $table) {
    $table->id();
    $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
    $table->string('folder_path');
    $table->integer('sort_position');
    $table->timestamps();
    $table->unique(['workspace_id', 'folder_path']);
});
```

A folder has no model of its own (it's derived purely from note paths at
render time, same as today) — this table is only an order index keyed by
path string, with no foreign key to any folder entity.

**Ordering rule:** for a given parent (workspace + `folder_path`), if
*any* sibling (note or folder) at that level has a `sort_position`, the
whole level is rendered sorted by `sort_position asc`. Items at that
level with no `sort_position` (e.g. a note created after the folder was
already put into manual order) are appended after all positioned items,
sorted alphabetically among themselves. If *no* sibling at a level has a
`sort_position`, the level falls back entirely to today's behavior
(alphabetical folders / active sort mode for files).

**Materialization:** there is no separate "materialize" step or
endpoint. The frontend always submits the *complete* new sibling order
for a level after a drag (see §5), computed from what's currently
displayed (which, for a never-touched level, is the alphabetical/sort-mode
order). The first `PUT` for a given level is therefore what assigns
`sort_position` to every item in it, in one write.

## 4. Backend API

**Reused, unchanged:** `POST /workspaces/{workspace}/notes/{note}/move`
(`WorkspaceNoteController::move`, `app/Http/Controllers/WorkspaceNoteController.php:101`)
— already accepts `{new_path}` and renames the note on disk, rewriting
inbound wikilinks. No frontend wrapper exists yet; one is added (§5).

**New — `GET /workspaces/{workspace}/note-tree/order`:** returns every
`folder_positions` row for the workspace: `[{folder_path, sort_position}]`.
Fetched once alongside `getNotes()` when a workspace loads.

**New — `PUT /workspaces/{workspace}/note-tree/order`:**

Request:
```json
{
  "folder_path": "docs",
  "items": [
    { "type": "folder", "path": "docs/archived" },
    { "type": "note", "id": 42 },
    { "type": "note", "id": 7 }
  ]
}
```

Validation (422 on failure):
- Every `type: "note"` id must belong to the workspace and its current
  `path` must resolve to a direct child of `folder_path`.
- Every `type: "folder"` path must be a virtual subfolder that currently
  exists directly under `folder_path` (derived by scanning workspace note
  paths — same logic `buildTree()` uses).
- `items` must contain *exactly* the current full set of direct children
  of `folder_path` — no more, no fewer. This prevents a stale client from
  silently dropping or duplicating siblings.

Effect (single transaction): each item gets `sort_position` set to its
index × 10 (0, 10, 20, ...) — notes via `notes.sort_position`, folders via
an upsert into `folder_positions`. The × 10 spacing has no functional
purpose beyond a little debugging headroom; there is no insert-between
operation to support (reordering always rewrites the whole level).

## 5. Frontend integration

**`frontend/src/services/api.ts` — new functions:**
```typescript
export async function moveNote(workspaceId: number, noteId: number, newPath: string): Promise<NoteMeta>
export async function reorderNoteTree(
  workspaceId: number,
  folderPath: string,
  items: Array<{ type: 'note'; id: number } | { type: 'folder'; path: string }>,
): Promise<void>
export async function getFolderPositions(workspaceId: number): Promise<Array<{ folder_path: string; sort_position: number }>>
```

**Dependency:** add `sortablejs` (+ `@types/sortablejs`) to
`frontend/package.json`. No Vue wrapper (`vuedraggable`) — the tree is a
recursive component, and `vuedraggable` is built around a single flat
`v-model` array, not nested lists; wiring plain `Sortable` instances
directly to each level's DOM list is the more direct fit.

**`Sidebar.vue`:**
- `sortBy` gains a 4th value, `'manual'`, added to the existing dropdown.
- `buildTree()` takes a `folderPositions: Map<string, number>` argument
  (built from `getFolderPositions()`, keyed by `folder_path`) and, when
  `sortBy === 'manual'`, sorts each level's children (folders and files
  together) by `sort_position` (item's own for notes, map lookup for
  folders), falling back per the rule in §3 for unpositioned items.
  When `sortBy !== 'manual'`, behavior is unchanged from today.

**`NoteTreeNode.vue`:**
- Each folder's `.children` list gets its own `Sortable` instance (via a
  directive or `onMounted`/`watch` on the element ref), all sharing
  `group: 'note-tree'` so items can be dragged between different levels'
  lists.
- `disabled: sortBy.value !== 'manual'` is passed reactively — the
  instance always exists, drag is just inert outside manual mode.
- `onMove(evt)`: returns `false` (rejecting the drop) when the dragged
  element represents a folder and `evt.from !== evt.to` — this is the
  mechanism that enforces "folders reorder, never reparent."
- `onEnd(evt)`:
  - `evt.from === evt.to` (pure reorder): read the list's current DOM
    order, map back to `{type, id|path}` items, call `reorderNoteTree()`
    for that one folder.
  - `evt.from !== evt.to` (only reachable for notes, per `onMove` above):
    call `moveNote(workspaceId, noteId, newPath)` where `newPath` is the
    destination folder's `fullPath + '/' + basename(note.path)`, then
    call `reorderNoteTree()` for the destination list only, with the
    note included at its dropped index. The source list is not touched —
    a gap left in its `sort_position` sequence is harmless.
- No dedicated drag handle: the whole row is draggable, matching
  Notion's affordance and requiring no new UI chrome. `Sortable`'s
  pointer-movement threshold before a drag starts means a plain click
  still navigates as it does today.
- The drop-target line between two siblings comes from `Sortable`'s
  built-in `.sortable-ghost` placeholder element; only its CSS is
  themed to match the existing design tokens (`--color-action`,
  `--color-hover`).

## 6. Deviation from the Markdown-on-disk invariant

`sort_position` and `folder_positions` are **not** written to any note's
front-matter — they are pure DB/UI-state, with no on-disk representation
and no round-trip through `MarkdownDocument`. This is a deliberate,
narrow exception to the spec's general "everything is reconstructable
from the Markdown files on disk" principle, matching existing precedent:
`NoteComment` and workspace notifications are already DB-only with no
Markdown backing. `php artisan vault:reindex` does not touch either
table — a reindex changes note content/metadata derived from disk, not
sidebar display order.

## 7. Testing

**Backend (PHPUnit):**
- `PUT .../note-tree/order` with a valid, complete `items` list persists
  sequential `sort_position` values (notes and `folder_positions` rows).
- 422 when `items` is missing a current sibling, includes an extra one,
  or references a note id from another workspace.
- `GET .../note-tree/order` (i.e. folder-positions) returns rows scoped
  to the requesting workspace only.
- The notes list endpoint (`WorkspaceNoteController::index` or
  equivalent) includes `sort_position` in each note's JSON.

**Frontend (Vitest):**
- `buildTree()`: with mixed `sort_position` values across folders and
  notes at one level, manual mode produces the interleaved order; an
  unpositioned item at an otherwise-positioned level sorts alphabetically
  after all positioned items.
- `onEnd` handler tested with a synthetic `evt` object (not a simulated
  real drag — jsdom does not reproduce native drag events reliably, the
  same gap noted for `blur` during Feature 1): same-list evt calls
  `reorderNoteTree` with the expected item list; cross-list evt calls
  `moveNote` then `reorderNoteTree` with the expected arguments.
- Sort dropdown includes `manual`; `Sortable` instance receives
  `disabled: true`/`false` matching `sortBy`.

**E2E (Playwright) — required, not optional manual spot-check:** native
drag events are exactly the case jsdom can't simulate, so this is the
only way to verify real behavior. New spec covering: reorder two notes
within one folder and confirm persisted order survives a reload; drag a
note from one folder into another and confirm its path changed and it
renders in the target folder; attempt to drag a folder into a different
parent folder and confirm it snaps back (rejected by `onMove`).

## 8. Out of scope / open questions carried forward

- Folder subtree reparenting (§2) — a much larger, separate feature if
  ever requested (bulk path rename + wikilink rewrite across every note
  under the folder).
- Drag-and-drop undo (§2).
- Concurrent edits to the same folder's order from two open tabs/sessions
  — last write to `PUT .../note-tree/order` wins, no locking. Acceptable
  at this app's expected scale (personal/small-team knowledge base).
