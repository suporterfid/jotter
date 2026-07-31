# Editor Breadcrumb Trail — Design

Date: 2026-07-31
Status: Approved for planning
Scope: Presentation-only. No new API endpoints, no database change, no
change to the Markdown-on-disk invariant.

## 1. Purpose

Feature 4 of 5 Notion-parity UX features proposed for Jotter (features 1,
page icon; 2, sidebar drag-and-drop; and 3, inline per-folder quick-create
are already implemented and merged). Notion shows a clickable breadcrumb
trail above the page title, letting the user navigate to any ancestor.
Jotter's `NoteEditor.vue` already renders the active note's full path as
plain text (`.editor-path`, confirmed at `NoteEditor.vue:35`) — this
feature turns that into clickable folder segments.

## 2. Scope

**In scope:**
- `.editor-path` renders each folder segment of `note.path` as a
  clickable element; the final segment (the file name) stays plain text.
- Clicking a folder segment reveals that folder in the sidebar tree:
  expands it (and every ancestor folder up to the root), scrolls it into
  view, and briefly highlights it.
- Opens the mobile sidebar (`isMobileSidebarOpen`) if it was closed, so
  the revealed folder is actually visible.

**Out of scope (confirmed during brainstorming):**
- No new "filter notes by folder" mode in the sidebar. The sidebar
  already has two filter mechanisms (`activeTag`, `searchQuery`); adding
  a third, folder-scoped one is unnecessary for what this feature needs
  and was explicitly rejected in favor of the simpler "reveal in tree"
  behavior, which reuses each folder's existing `expanded` state instead
  of introducing new filter state.
- No change to the note title (`.editor-title`) or its surrounding
  page-icon UI (feature 1) — only `.editor-path` changes.
- No breadcrumb truncation/collapsing for very deep paths (e.g. Notion's
  "..." middle-collapse for long trails) — out of scope; Jotter's vault
  paths are not expected to run deep enough in practice to need it, and
  it can be added later without touching this design's data flow.

## 3. Component changes

### `NoteEditor.vue`

Replace the plain-text `.editor-path` span:
```html
<span class="editor-path" data-testid="editor-path">{{ note.path }}</span>
```
with a computed breadcrumb list. `pathSegments` splits `note.path` on
`/`; every segment except the last is a clickable button whose own
cumulative path (folder path up to and including that segment) is what
gets emitted:
```typescript
const breadcrumbSegments = computed(() => {
  const parts = props.note.path.split('/')
  const fileName = parts[parts.length - 1]
  const folders = parts.slice(0, -1)
  return {
    folders: folders.map((name, index) => ({
      name,
      path: folders.slice(0, index + 1).join('/'),
    })),
    fileName,
  }
})
```
Each folder segment emits `(e: 'reveal-folder', folderPath: string): void`
on click. The file name renders as plain text (matches the existing
`data-testid="editor-path"` container for backward test compatibility —
the testid stays on the outer wrapper, individual segments get their own
`data-testid="editor-path-segment"` for targeted testing).

### `App.vue`

New state:
```typescript
const revealFolderRequest = ref<{ path: string; nonce: number } | null>(null)
```
New handler, wired to `NoteEditor`'s `@reveal-folder`:
```typescript
function handleRevealFolder(folderPath: string) {
  revealFolderRequest.value = { path: folderPath, nonce: Date.now() }
  isMobileSidebarOpen.value = true
}
```
The `nonce` (not otherwise read) exists so clicking the *same* segment
twice in a row still produces a new object reference, since `Sidebar`'s
watcher (below) needs to re-fire even when `path` is unchanged — clicking
"docs" again after having since collapsed it manually should still
re-expand and re-scroll to it.
`revealFolderRequest` is passed to `<Sidebar>` as a new prop.

### `Sidebar.vue`

New prop: `revealFolderRequest?: { path: string; nonce: number } | null`.
A `watch` on this prop:
1. Passes `props.revealFolderRequest?.path ?? null` down to every
   root-level `<NoteTreeNode>` as a new `revealPath` prop.
2. After `nextTick()`, queries
   `this.$el.querySelector('[data-item-type="folder"][data-item-path="<path>"]')`
   (the `data-item-path` attribute already exists on every folder row,
   added in feature 2) and calls `scrollIntoView({ block: 'center',
   behavior: 'smooth' })` on it, then toggles a `.folder-row-highlight`
   class for ~1.5s (a plain `setTimeout`, matching the existing
   short-lived UI feedback pattern already used elsewhere in the app —
   no new animation library).

### `NoteTreeNode.vue`

New prop: `revealPath?: string | null`. A `watch` on it: if
`revealPath` equals `node.fullPath`, or `node.fullPath` is a proper
prefix of `revealPath` (i.e. this folder is an ancestor of the revealed
one), set `expanded.value = true`. The prop is threaded through the
existing recursive `<NoteTreeNode>` invocation inside `.folder-children`
exactly like `selectedNoteId`/`depth` already are, so it reaches every
depth automatically.

## 4. Testing

**`NoteEditor.spec.ts`:**
- A note at `docs/archived/note.md` renders 2 clickable folder segments
  (`docs`, `archived`) and 1 plain-text file-name segment (`note.md`).
- A note at the vault root (`note.md`, no `/`) renders 0 folder segments,
  just the plain file name — no empty/broken breadcrumb for root notes.
- Clicking the "archived" segment emits `reveal-folder` with
  `docs/archived` (the cumulative path up to and including that segment,
  not just its own name).

**`Sidebar.spec.ts`:**
- Setting `revealFolderRequest` to `{ path: 'docs/archived', nonce: 1 }`
  results in both `docs` and `docs/archived` folder rows being expanded
  (`.folder-children` visible) after the next tick.

**`NoteTreeNode.spec.ts`:**
- A folder node with `fullPath: 'docs'` and `revealPath: 'docs/archived'`
  expands (ancestor case).
- A folder node with `fullPath: 'docs/archived'` and
  `revealPath: 'docs/archived'` expands (exact-match case).
- A folder node with `fullPath: 'other'` and `revealPath: 'docs/archived'`
  does **not** expand (unrelated folder, proves the prefix check is
  exact-segment, not a naive string-prefix that would wrongly match
  `docsx` against `revealPath: 'docs'`).

**`App.spec.ts`:**
- `handleRevealFolder('docs')` sets `isMobileSidebarOpen` to `true`.

No backend test changes — no backend code changes in this feature.

## 5. Out of scope / open questions carried forward

- No folder-filtering mode in the sidebar (§2).
- No breadcrumb path truncation for deep trees (§2).
- The highlight duration/easing is a fixed constant, not themed through
  a design token — acceptable for a one-off transient effect, revisit
  only if a similar "flash to draw attention" pattern gets reused
  elsewhere in the app.
