# Jotter — Decision Record

Immutable record of roadmap/spec conflicts and cross-cutting technical decisions. Once a decision lands here it is resolved — do not re-litigate it without a new decision entry that explicitly supersedes the old one. Split out of `BACKLOG.md` (#208) to stop decision history from being reconciled against an active backlog file, the failure mode documented in `docs/20260729-jotter-audit.md`.

---

## Roadmap/spec conflicts (§14.5 / Issue #50)

Six roadmap items conflicted with the spec's hard constraints (§1 Markdown-on-disk invariant, §4 shared-hosting constraints, §8 security constraints). Each was resolved as follows:

- [x] **C1 — Realtime collaboration vs. async-first**: Jotter remains strictly async-first per §4 (no long-running daemons or WebSocket processes).
- [x] **C2 — Structured collections and views**: Implemented via rebuildable `note_properties` table projection from YAML front-matter without breaking Obsidian plain Markdown compatibility (§1).
- [x] **C3 — Synced / reusable blocks**: Only syntax that degrades to readable plain Markdown elsewhere is supported.
- [x] **C4 — Visual canvas / whiteboard**: Out of scope for server core; mobile/canvas usage is served by Obsidian synced via WebDAV.
- [x] **C5 — Offline-first and mobile**: WebDAV + Obsidian is the offline/mobile story (local-first inversion).
- [x] **C6 — Version history storage**: Selected Option 1 (DB-stored deduplicated snapshots in `note_revisions` table with `vault:prune-revisions` retention).

`BACKLOG.md`'s **Needs a decision** section previously still listed C1–C6 as open blockers after they were resolved here — a self-contradiction between two sections of the same file, found and fixed as #141. That reconciliation failure is part of why this record now lives in its own file.

---

## Decision Record — Typed Property Model (Issue #79)

The property model projects YAML front-matter key-value pairs into typed, indexed MySQL storage (`note_properties`) while keeping Markdown files on disk as the single source of truth.

### 1. Schema & Data Storage Shape
- **Table**: `note_properties` (`id`, `note_id`, `name`, `type`, `value_string`, `value_numeric`, `value_boolean`, `value_datetime`, `value_json`, `created_at`, `updated_at`).
- **Indexes**: `(note_id, name)` unique key; composite search indexes on `(name, value_string)`, `(name, value_numeric)`, `(name, value_boolean)`, `(name, value_datetime)`.

### 2. Type Inference Matrix
| YAML Input Pattern | Inferred Type | Stored Column |
| :--- | :--- | :--- |
| `"active"`, `"high"` | `string` | `value_string` |
| `42`, `3.14159`, `-10` | `numeric` | `value_numeric` |
| `true`, `false` | `boolean` | `value_boolean` |
| `"2026-07-28"`, `"2026-07-28T10:00:00Z"` | `datetime` | `value_datetime` |
| `["apple", "banana"]`, `[1, 2, 3]` | `list` | `value_json` |
| Nested objects / dicts | `json` | `value_json` |

### 3. Mixed-Type Conflict Resolution Policy
If Note A assigns string `"2"` to property `priority` and Note B assigns integer `2` to `priority`:
- Both rows exist independently in `note_properties`. Note A populates `value_string`, Note B populates `value_numeric`.
- Query filters match within their targeted typed column without forcing lossy type coercions or throwing runtime errors.

### 4. Tag Relationship
- Note tags remain first-class in `tags` and `note_tags`.
- Front-matter `tags:` array continues to project into `tags` / `note_tags`. Property projection is strictly additive.

---

## Decision — WYSIWYG editor (Issue #263)

`BACKLOG.md`'s "Needs a decision" section carried #263 open since 2026-08-03: is Notion-*feel* the goal for `NoteEditor.vue` (→ inline WYSIWYG, a multi-PR epic), or is Notion-*calm* enough via chrome/spacing work alone, keeping an honest Markdown editor?

**Decided 2026-08-05: Notion-feel is the goal. Option (b) — inline WYSIWYG over Markdown.**

### Options considered (per the audit's Part D.6, ascending cost)
- (a) Keep the `<textarea>`, drop the Edit/Split/Preview toggle, default to a single rendered-ish view.
- (b) Inline WYSIWYG over Markdown (TipTap/Milkdown/ProseMirror with a Markdown serializer), preserving the Markdown-on-disk invariant.
- (c) A real block model.

### Rationale
(c) risks the Markdown-on-disk invariant itself (spec §1 / `AGENTS.md`) by moving the source of truth toward a block model — rejected outright, consistent with C3's existing "only syntax that degrades to readable plain Markdown elsewhere is supported" position above. (a) is effectively already covered by #250–#262's chrome/spacing work, and the audit's own text states no amount of that work closes the Notion gap while a monospace textarea remains the primary editing surface. (b) is the only option that reaches real Notion parity while keeping the invariant intact.

Within (b), **Milkdown** (not TipTap) is the chosen library: its document model is Markdown-native (built on `remark`), so round-trip fidelity is part of each node's spec rather than a serializer bolted onto a JSON-first document model. Given the invariant is the entire reason this decision required deliberation, "Markdown is the native format" outweighs TipTap's larger plugin ecosystem.

### Scope and sequencing
Full phased breakdown, risks, and non-goals: `docs/20260805-jotter-wysiwyg-editor-epic-spec.md`. Filed as five sequenced issues (#321–#325), each gated on the previous one being merged and green, per `AGENTS.md`'s PR-sequence rule. Implementation has not started as of this decision — this entry and the spec doc are docs-only, matching how #278 recorded the original decision-needed state and #309 filed the Trello board-parity epic's issues without implementing them in the same PR.

---

## Adding a new decision

Append a new dated `##` section below this line, in the same format: the question being decided, the options considered, and the choice made with its rationale. Do not edit a resolved decision's original entry — supersede it with a new one that references the old.
