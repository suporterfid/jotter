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

## Decision — Note-level ACL inheritance and application boundary (Issue #349)

**Decided 2026-08-17:** add Confluence-style restrictions as MySQL application state. A note with no ACL entries inherits the workspace authorization; adding the first entry restricts the note to matching principals. The first release applies the rule to the note itself only: folder ancestry does not implicitly grant or revoke access, including after a note is moved or restored from trash.

### Selected contract

- ACL entries grant `view` or `edit` to a local user or a workspace-scoped group. `edit` implies `view`.
- Global administrators and workspace `owner`/`admin` members bypass note restrictions. Workspace `editor` and `viewer` members do not bypass a restriction without a matching grant.
- ACL and group-management operations are limited to global administrators and workspace `owner`/`admin` members. Hidden notes use not-found behavior so their existence is not disclosed.
- Service tokens have no local user principal and can read only unrestricted notes within their existing workspace audience until a service-principal ACL contract exists.
- Public publishing excludes all restricted notes by default. Explicit public sharing remains a separate decision and issue (#355).
- ACLs are never serialized into front matter, Markdown, exports, or vault filenames.

### Rejected alternatives and boundary

Front-matter ACLs were rejected because permissions are application state and must not alter the Markdown-on-disk source of truth. Folder-descendant inheritance was rejected for this release because path moves and raw filesystem edits would make the effective policy ambiguous; a future hierarchy decision can supersede this entry. Letting every workspace editor bypass restrictions was rejected because it defeats the purpose of per-note restriction. Publishing restricted notes was rejected because a public projection cannot safely infer a viewer's grants.

These controls protect Jotter application surfaces backed by the MySQL index. A user with direct filesystem access to the vault can bypass them, as can a raw WebDAV/filesystem client that bypasses Jotter's authorized endpoints; deployment and hosting permissions must protect the vault root separately.

Append a new dated `##` section below this line, in the same format: the question being decided, the options considered, and the choice made with its rationale. Do not edit a resolved decision's original entry — supersede it with a new one that references the old.

## Decision — PDF export architecture (Issue #354)

**Decided 2026-08-18:** add private PDF export at note and workspace scope without changing Markdown-on-disk as the source of truth.

### Options considered

- Headless Chromium: rejected for shared hosting because it adds a heavyweight runtime and shell/process dependency.
- Remote conversion service: rejected because note content and assets would leave the deployment boundary.
- Pure PHP renderer with synchronous workspace generation: rejected because large workspaces can exceed request limits.
- Dompdf plus bounded queued workspace processing: selected.

### Selected contract and rationale

Single-note export uses `dompdf/dompdf` with `MarkdownServerRenderer`, published CSS/fonts, remote fetching disabled, and local assets resolved through `VaultPathGuard`. Workspace export stores the ACL-filtered note id snapshot, dispatches `App\Jobs\GeneratePdfExport` through `JobDispatcher`, and is processed by `pdf:process-exports --limit=N`. Artifacts are written below a private configurable directory, exposed only through an authorized status/download endpoint, and expire according to `JOTTER_PDF_RETENTION_HOURS`.

This keeps the implementation compatible with shared hosting, preserves per-request ACL decisions, bounds background work, and prevents server-side fetches of remote URLs.

---

## Decision — Per-note public sharing boundary (Issue #355)

**Decided 2026-08-19:** explicit public sharing is a token-authenticated exception to the default ACL-filtered workspace publishing rule. It grants access to one note at a time and does not expose workspace navigation or metadata.

### Selected contract

- A share stores only a SHA-256 token hash. The opaque plaintext token and URL are returned only by the authenticated creation response; subsequent status responses do not reconstruct them.
- Creating and revoking a share requires note edit access through `NoteAccess`; restricted notes remain hidden from unauthorized subjects.
- Optional expiry and explicit revocation both make the public HTML and attachment routes return 404. Deleted notes and invalid tokens use the same not-found behavior.
- `GET /share/{token}` renders only the selected note through the published-page shell. Wikilinks are plain text, external embeds are disabled, and registered local attachments use the same token-scoped route.
- Share creation and revocation are recorded in the audit log without token or URL values. Token rotation is performed by revoking the old share and creating a new one.

This boundary preserves the Markdown-on-disk source of truth and shared-hosting constraints while making per-note public exposure explicit, revocable, and non-enumerable.
