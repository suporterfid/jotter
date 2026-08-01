# Collapsible Note Editor Panels — Design

Date: 2026-08-01
Status: Approved for planning
Scope: Frontend-only. No API changes, no database changes.

## 1. Purpose

Reported in production: `PropertiesPanel` occupies most of the screen
and has no way to collapse it. Root cause, confirmed via
systematic-debugging: `PanelHeader.vue` (the shared header used by
`PropertiesPanel.vue`, `CommentsPanel.vue`, `BacklinksPanel.vue`,
`OutgoingLinksPanel.vue`, and `UnlinkedMentionsPanel.vue`, confirmed via
`grep -rl PanelHeader frontend/src/components/*.vue`) renders only a
title and count — no chevron, no toggle, no collapse mechanism at all.
Every one of these five panels is mounted unconditionally in-flow in
`NoteEditor.vue`, each always fully expanded, with no way to hide it.
This is a missing feature, not a regression — none of the five panels
has ever had a collapse capability.

## 2. Scope

**In scope:**
- All five panels sharing `PanelHeader.vue` become collapsible via a
  chevron button in the header.
- `PropertiesPanel` starts collapsed by default; the other four start
  expanded by default (matches today's behavior for those four, only
  changes the one panel that was reported as a problem).
- Collapse state persists across page reloads via `localStorage`, one
  key per panel type (not per note) — closing Properties once keeps it
  closed everywhere until explicitly reopened.

**Out of scope:**
- No change to panel content/behavior beyond adding the collapse
  wrapper — the properties list, comment form, backlink list, etc. stay
  exactly as they are today, just hideable.
- No per-note collapse memory (e.g. "closed only on this note") — the
  preference is global per panel type, per the brainstorming decision.
- No drag-to-resize or reordering of panels — purely a show/hide toggle.

## 3. Architecture

A new composable, `useCollapsiblePanel(key: string, defaultCollapsed:
boolean)`, centralizes the localStorage read/write/toggle logic so it's
written once instead of five times:

```typescript
// frontend/src/composables/useCollapsiblePanel.ts
import { ref, watch } from 'vue'

export function useCollapsiblePanel(key: string, defaultCollapsed: boolean) {
  const storageKey = `jotter-panel-collapsed:${key}`
  const collapsed = ref(defaultCollapsed)

  const stored = localStorage.getItem(storageKey)
  if (stored !== null) {
    collapsed.value = stored === 'true'
  }

  watch(collapsed, (value) => {
    localStorage.setItem(storageKey, String(value))
  })

  function toggle() {
    collapsed.value = !collapsed.value
  }

  return { collapsed, toggle }
}
```

`PanelHeader.vue` gains a `collapsed: boolean` prop and a `(e: 'toggle'):
void` emit. It renders a chevron button (rotates 90° when collapsed,
matching the existing chevron-rotation pattern already used for sidebar
folders in `NoteTreeNode.vue`) that emits `toggle` on click — the header
itself is the only new UI surface; the five consuming panels don't gain
any new visible markup beyond wrapping their existing body in `v-show`.

Each of the five panel components:
1. Calls `useCollapsiblePanel('<panel-key>', <default>)` — key and
   default per panel:
   - `PropertiesPanel.vue`: `useCollapsiblePanel('properties', true)`
   - `CommentsPanel.vue`: `useCollapsiblePanel('comments', false)`
   - `BacklinksPanel.vue`: `useCollapsiblePanel('backlinks', false)`
   - `OutgoingLinksPanel.vue`: `useCollapsiblePanel('outgoing-links', false)`
   - `UnlinkedMentionsPanel.vue`: `useCollapsiblePanel('unlinked-mentions', false)`
2. Passes `:collapsed="collapsed"` and `@toggle="toggle"` to its
   `<PanelHeader>`.
3. Wraps its existing body markup (everything currently rendered below
   `<PanelHeader>`) in `<div v-show="!collapsed">...</div>` — no other
   template changes.

## 4. Testing

**`useCollapsiblePanel.spec.ts`:**
- Returns `collapsed.value === defaultCollapsed` when no localStorage
  key exists yet.
- Returns the stored value (not the default) when a key already exists.
- `toggle()` flips `collapsed.value` and persists the new value to
  `localStorage`.

**`PanelHeader.spec.ts`:**
- Clicking the chevron button emits `toggle`.
- The chevron has a `collapsed` CSS class (or equivalent rotation state)
  when the `collapsed` prop is `true`.

**One representative panel test updated per panel** (`PropertiesPanel`,
`CommentsPanel`, `BacklinksPanel`, `OutgoingLinksPanel`,
`UnlinkedMentionsPanel` — each already has its own spec file per prior
session work):
- The panel's body is hidden (`v-show` → `display: none`) when its
  `useCollapsiblePanel` composable reports `collapsed: true`.
- Clicking the header's chevron toggles the body's visibility.

No backend test changes — no backend code changes in this feature.

## 5. Out of scope / open questions carried forward

- Per-note collapse memory (§2).
- Drag-to-resize (§2).
- The workspace/tenant switcher gaps found in the same investigation are
  a separate, larger feature — tracked as its own follow-up brainstorming
  cycle, not part of this spec.
