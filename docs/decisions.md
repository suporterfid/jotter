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

## Decision — Roadmap baseline provenance (Issue #360)

**Decided 2026-08-19:** confirm that the current-state gap analysis in `docs/20260727-jotter-roadmap-ai-agent.md` was written for a different product carrying the same Jotter name. The roadmap remains useful for sequencing, dependencies, and feature direction, but its assessment of what this repository already delivers is not authoritative.

### Question

Should the roadmap's baseline and feature-gap summary be used to determine what this repository lacks, or should the repository's own specification and delivered-state table govern planning?

### Options considered

- Treat the roadmap baseline as authoritative: rejected because it describes offline-first behavior, Material You styling, and realtime collaboration, while this repository is a Laravel/Vue server application for shared hosting; it also calls search, nested folders, tags, backlinks, and attachments missing even though they shipped in v0.
- Discard the roadmap entirely: rejected because its sequencing logic, dependency map, and feature definitions remain useful planning signals.
- Split the authority: selected. Keep the roadmap for direction and sequencing; use this specification's constraints and §14.3 delivered-state table for current-state claims.

### Rationale

The evidence is already recorded in `docs/jotter-initial-spec-and-build-plan.md` §14.1: the baseline's product description does not match this repository, and five of its six top priorities were already delivered. The repository's server-side architecture, shared-hosting constraints, Markdown-on-disk invariant, and actual delivered-state table are the reliable planning baseline. This closes the final open decision tracked by #360 without deleting the roadmap or re-planning already shipped work.

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

---

## Decision — Advisory content approval workflow (Issue #359)

**Decided 2026-08-19:** add per-note review/sign-off state as MySQL application metadata without changing the Markdown source of truth or treating approval as publication.

### Selected contract

- Workflow state is stored in a one-to-one `note_review_workflows` projection with four states: `draft`, `in_review`, `changes_requested`, and `approved`.
- Reviewer assignment, submission timestamps, and the last approved `content_hash` are application state. No workflow field is written to Markdown, YAML front matter, exports, or vault filenames.
- Editors may submit a note. The assigned reviewer, workspace owner, or workspace admin may approve or request changes when they can view the note. Viewers and service tokens cannot mutate review state.
- An approval is current only while the projected `notes.content_hash` equals the saved approved hash. Any Jotter, Obsidian, WebDAV, MCP, or filesystem edit that changes the hash makes the approval stale and requires another submission.
- The workflow is advisory rather than a hard write lock. Direct vault writers remain able to edit Markdown because the hosting model cannot enforce an application-only lock over the source-of-truth files.
- Approval does not automatically publish a page or alter `WorkspacePublishController`; publication remains a separate concern.
- Review transitions and invalidations are immutable `audit_log` events recorded through `AuditRecorder`, without note bodies, secrets, or approval reasons that exceed the existing metadata boundary.

### Options considered

- Front-matter state: rejected because Obsidian/WebDAV can edit it freely and it would contaminate the portable Markdown source.
- Hard application write lock: rejected because direct vault access bypasses Jotter's HTTP authorization layer and a false guarantee would be worse than an explicit advisory workflow.
- Separate approval database with no content hash: rejected because out-of-band edits would leave an approved record attached to a different document.
- MySQL workflow projection with hash-based staleness: selected because it preserves the source-of-truth invariant, works on shared hosting without a daemon, and exposes the limitation honestly.

This decision resolves the semantics required before implementing #359. A future decision may add publication gating or a stronger deployment-level lock, but that would supersede this entry and require a separate issue.

---

## Decision — Single engine; hosting is configuration, not a fork (feat/brand-and-plan)

**Decided 2026-08-31:** Jotter is offered both as MIT self-hosted software and as a hosted service ("Cadernia"). Both run the same engine from the same repository. Branding and commercial plan state are configuration and data of that engine, with defaults that leave a self-hosted installation unchanged.

### Selected contract

- Branding is environment configuration (`JOTTER_BRAND_NAME`, `JOTTER_BRAND_LOGO_URL`, `JOTTER_BRAND_SUPPORT_URL`, `JOTTER_BRAND_TERMS_URL`, `JOTTER_BRAND_PRIVACY_URL`, `JOTTER_BRAND_POWERED_BY`) exposed through `GET /api/auth/config`. Defaults reproduce the stock identity; `powered_by` defaults to `true` and links to the repository.
- Plan state lives on `tenants` (`plan_status`, `trial_ends_at`, `plan_name`, `plan_seats`) with `self_hosted` as the default that disables every rule. Restrictions are read-only gates (402) on writes; reading, search, export, and login are never restricted.
- Plan state changes only through operator commands (`tenant:plan`, `tenant:show`) and one scheduled transition (`tenant:expire-trials`). Every change is audited.
- No payment, checkout, invoice, or billing-provider code enters the repository. Billing is external; the operator mirrors its outcome with `tenant:plan`.
- No hosted-only branch, build flag, or fork. Any hosted feature must ship with a default that keeps self-hosting identical and must be usable by any self-hoster who wants it.

### Options considered

- Fork or private downstream branch for the hosted product: rejected — divergence tax, duplicated security fixes, and the MIT engine would stop being the product actually run.
- Build-time white-label (compile-time constants): rejected — one artifact must install for both audiences (`jt release` is shared), and per-client configuration is already the deployment model.
- Payment integration inside the engine: rejected — it would force billing-provider dependencies and secrets on every self-hoster; a status field the operator sets is sufficient and auditable.
- Runtime configuration with `self_hosted` default: selected.

This entry governs future hosted-mode work; a decision that introduces billing code or a hosted-only fork would have to supersede it explicitly.

---

## Decision — Scoped credentials and MCP write tools (brain multi-agent epic)

**Decided 2026-11-03:** machine tokens become scoped credentials, and MCP write tools are back in scope behind those scopes. This supersedes the "Write Tools Decision & Scope (Deferred)" section of `docs/mcp.md`, which was a scope note rather than an entry here. Spec: `docs/superpowers/specs/2026-11-03-jotter-brain-multiagent-design.md`; plan: `docs/superpowers/plans/2026-11-03-jotter-brain-multiagent.md`.

### Question

One Jotter deployment is to serve as the shared "brain" of several AI clients (Claude Code, Codex, Hermes, Claude web, ChatGPT). Before this decision a machine token acted as its full user: it wrote through REST and WebDAV, reached `/api/admin/*` when the user was an administrator, and authenticated only under the local identity provider. Under what rules may agents read and write?

### Selected contract

- **Scopes.** A credential carries `mcp:read`, `mcp:write`, `api:read`, `api:write`. `/api/mcp` needs `mcp:read`; other API routes need `api:read` for GET/HEAD/OPTIONS/PROPFIND and `api:write` otherwise (WebDAV included). `/api/admin/*`, `/api/auth/*`, and `/api/user/*` never accept a credential. A missing scope answers 403 with `WWW-Authenticate: Bearer error="insufficient_scope"`.
- **D1 — existing tokens become `mcp:read` only.** Breaking for anyone using a token on REST or WebDAV; the CHANGELOG gives the reissue command. The only documented use was MCP, and keeping implicit REST access is the vulnerability being fixed.
- **D2 — workspace allowlist.** Effective workspaces are the owner's explicit memberships, intersected with the token's `workspace_ids` when set, within the token's tenant. A token owned by an administrator must list its workspaces explicitly at issuance; legacy administrator tokens were backfilled with every workspace of their tenant, preserving their MCP reach.
- **D3 — no administrator bypass.** A credential subject never carries the administrator flag, so note ACLs apply to agents and admin routes stay closed by two independent checks.
- **D4 — OAuth (later PR) is off by default** (`JOTTER_OAUTH_ENABLED=false`); a deployment opts in.
- **D5 — Hermes uses a manual token** in this cycle.
- **D6 — delivery order:** scoped credentials → protocol polish → write tools → brain tools → OAuth → client docs.
- **D7 — `_brain/`, `.trash/`, `_resources/`, `_templates/`, `.obsidian/` are never writable by a credential**, whatever its write paths say; a write-path glob must start with a literal folder.
- **D8 — OAuth grants only `mcp:read`, `mcp:write`, `offline_access`**, never `api:*`.
- Credentials resolve for every identity provider through `CredentialScopedIdentityProvider` (a decorator over the configured provider) and `MachineTokenResolver`; an unknown or expired Jotter-prefixed token answers 401 and never falls back to a session.

### How the deferral's revisit conditions are met

`docs/mcp.md` deferred write tools until (1) an `mcp:write` scope existed in token issuance and (2) per-workspace write rate limiting and locking existed for MCP. (1) ships with this decision; (2) ships in the protocol PR (rate limits) and the write-tools PR (per-note lock, optimistic concurrency, audit). No write tool is exposed before both are merged.

### Options considered

- Keep tokens as full user impersonation and add write tools: rejected — a leaked read-only agent token could already write everywhere the user can, including admin routes.
- Separate token tables per audience (MCP vs REST): rejected — two issuance paths and two resolvers for one concept; scopes on one table express the same thing.
- JWT access tokens: rejected — key management on shared hosting; opaque hashed tokens already work.
- Scoped opaque tokens with a provider decorator: selected.

---

## Decision — Opt-in semantic search over a rebuildable embeddings projection (#412)

**Decided 2026-11-06.** The brain spec listed semantic search as a v1 non-goal, a candidate for TaskConnect, because embeddings seemed to need a long-running process (spec §4: no daemon, no `exec`, short execution limits). This entry records how it fits those constraints anyway.

### Question

Where are embeddings computed and stored, how do they stay current without a worker, and how is note privacy kept when text must reach an embedding model?

### Selected contract

- **Computed by an external, OpenAI-compatible `/embeddings` endpoint** (`JOTTER_EMBEDDINGS_URL`). That can be OpenAI, a local Ollama or LM Studio, or any compatible gateway, TaskConnect included. Jotter runs no model; the provider is a configuration seam (`EmbeddingProvider`).
- **Stored in MySQL as a projection** (`note_embeddings`: one row per chunk, L2-normalised float32 vector, the note's `content_hash` and the model at embedding time). It is derived from the notes' indexed text like the rest of MySQL (spec §1): `semantic:index --rebuild` deletes and recreates it, and nothing in it is a source of truth.
- **Indexing never runs in a web request.** The scheduler runs `semantic:index --limit=100` every five minutes, the same cron pattern as `pdf:process-exports`. Each run embeds at most 100 notes whose `content_hash` or model changed, so a large vault converges over several runs within shared-hosting time limits. A provider error stops the run, and the next one retries. The `JobDispatcher` seam is not needed for this; moving the work to TaskConnect later means pointing the provider URL at it, or replacing the command's executor.
- **A query makes one short embedding call** (5 s timeout) and compares vectors in PHP, with a dot product over at most `JOTTER_SEMANTIC_MAX_CHUNKS` (20,000) chunks, because MySQL 8 has no vector index. The default 512 dimensions keep that well under a second. When semantic search is off, the workspace has not opted in, the index is empty, or the call fails, the tool falls back to FULLTEXT and says so (`mode: "fulltext"`, `reason`).
- **Privacy: opt-in twice.** `JOTTER_SEMANTIC_ENABLED=false` by default, and each workspace stays off until `semantic:index --workspace=<slug> --enable`. Note text, including query text, is sent to the provider only for opted-in workspaces. Opting out deletes that workspace's rows; turning the feature off globally deletes all of them on the next run. A restricted workspace (for example `brain-restrito`) is simply never opted in, or uses a provider on the same machine.
- **Access:** results are filtered with `NoteAccess::scopeVisible` before scoring, and the MCP tool goes through the same workspace authorization as `search_notes`, so a token's workspace list and note ACLs both apply.

### Options considered

- MySQL 9 `VECTOR` type or a vector database: rejected for now; shared hosting runs MySQL 8 and cannot host another service.
- Embedding on save, inside the request: rejected; it adds provider latency and failures to every save.
- Embedding the vault on disk in a worker or a TaskConnect job: possible later through the provider seam; not needed to ship.
- Scheduler-driven, bounded indexing with an opt-in per workspace and a FULLTEXT fallback: selected.


---

## Decision — Jotter stays MIT-licensed; no dual license (#390)

**Decided 2026-11-08 by the owner.** The 2026-08-31 triage (#390, item 5) noted that `LICENSE` is MIT, which allows anyone to resell the software, and asked for a decision if a paid self-hosted licence was ever intended.

### Selected contract

- Jotter remains under the **MIT License** for all of its code, including the hosted-mode features (plan/trial gates, branding, provisioning).
- There is no dual licence and no proprietary add-on. Revenue, if any, comes from operating hosted instances and from support, not from licensing the code.
- New code contributed to this repository is MIT. A future paid component would need a superseding decision entry and would have to live outside this repository.

### Options considered

- Dual licence (AGPL + commercial): rejected; it restricts the self-hosting audience the product is built for (shared hosting, "the cPanel your grandpa never gave up").
- Open core with a proprietary add-on: rejected for now; there is no add-on worth separating, and two codebases double the maintenance on shared hosting.
- Keep MIT: selected.

---

## Decision — Erasure from backups: tombstones replayed on restore, no crypto-shredding yet (LGPD block 4c)

**Decided 2026-11-12 by the owner** (proposed the same day, approved as written, with one refinement found while implementing it: see "Row identity" below). Background: `BACKLOG.md` → "LGPD compliance on shared hosting", block 4c.

### Question

`tenant:delete` and `user:delete` (LGPD art. 16 and 18 VI) remove data from the live installation. Copies of that data remain in:

- the nightly instance backups: every Jotter table plus every vault file, encrypted as a whole when `JOTTER_BACKUP_KEY` is set, with the newest `JOTTER_BACKUP_KEEP` (7) kept on the server;
- the copies the operator keeps off the server, under a retention Jotter does not control.

Two risks follow:

1. **Resurrection.** `jotter:restore` replaces every table in the archive, so restoring a backup taken before a deletion brings the deleted tenant or person back, vault files included.
2. **Lingering copies.** The data stays readable, to whoever holds both the archive and its key, until the last copy that contains it is gone.

What should Jotter guarantee, and how, on shared hosting (no daemon, no `exec`, pure PHP)?

### Proposed contract

1. **Tombstones (the erasure log).** Every `tenant:delete` and `user:delete` appends one line to an append-only erasure log:
   - each line is `{type: tenant|user, id, deleted_at, actor}`, plus the tenant `slug`;
   - there are no names or e-mails: the internal id is enough, because a restore brings rows back with the same ids.

   The log lives in `storage/app/private/erasure-log.ndjson`, outside the database, so a restore that rewrites the tables cannot erase it. **Every new backup also carries a copy** (`erasure-log.ndjson` inside the archive), so the newest archive anywhere always has the full log.
2. **Replay on restore.** After the database and vault files are restored, `jotter:restore` reads the tombstones from three sources:
   - the live log, if present;
   - the archive's own copy;
   - any `--erasure-log=<file|newer archive>` the operator passes.

   It deletes again, through the same `TenantDeletion` / `UserDeletion` code, every tenant or account named there, and it never asks for confirmation for this step. The restore summary lists what it re-deleted, and an audit row records it (`instance.erasures_replayed`).

   Restoring an archive older than the newest tombstone without a log that covers it prints a warning and needs `--force`. That protects a disaster recovery onto a new host, where the live log is gone, from silently bringing data back.
3. **Bounded copies.** The retention of everything stays explicit:
   - `JOTTER_BACKUP_KEEP` stays the on-server limit;
   - the deployment and hosted-operations docs ask operators to rotate their off-server copies within a stated window (proposed: 30 days);
   - `jotter:doctor` warns when backups are not encrypted (4a) and when the erasure log is missing or not writable.
4. **Privacy policy.** The draft says it plainly: deleted data disappears from the backups when the oldest copy that contains it is discarded (at most N days); backups are encrypted; and **a restore never brings deleted data back**.
5. **Retention of the log itself.** Tombstones hold ids, a tenant slug and timestamps, not names or e-mails. They are kept for as long as the longest backup retention plus a margin (`JOTTER_ERASURE_LOG_DAYS`, proposed default 400), then pruned by the nightly retention commands.

### Row identity (refinement made while implementing)

After a restore, MySQL's auto-increment counter follows the restored rows, so a row created later can receive the id of a row that was deleted. A tombstone that matched by id alone would then delete an innocent new account at the next restore. Each tombstone therefore also records the erased row's own `created_at`, which is not personal data. The replay deletes only a row whose id **and** `created_at` both match; anything else counts as "already gone".

### Options considered

- **A. Status quo + documentation only.** Rejected: a restore would still resurrect deleted data, which contradicts the deletion the person was promised.
- **B. Tombstones replayed on restore + bounded, encrypted backups (proposed).**
  - Small: one log file, one replay step in `jotter:restore`, docs and a doctor check.
  - It keeps every backup restorable with the key the operator already holds, and it fits pure PHP and cron.
  - What it does not do: make old copies unreadable before they rotate out. That is the usual practice for backups. European data-protection regulators (the UK ICO calls it "putting the data beyond use") accept it when the copies are protected, never used to restore the deleted data, and deleted on a schedule. **Whether the ANPD reads the LGPD the same way is for the owner's lawyer to confirm.**
- **C. Scrub the local backups on deletion.** Rewrite every archive on the server without the deleted tenant: decrypt, filter, rebuild, re-encrypt. Rejected:
  - it means gigabytes of work per deletion, inside shared-hosting time limits;
  - it never reaches off-server copies;
  - B already prevents the only harmful use of the copies, which is restoring them.
- **D. Crypto-shredding with per-tenant keys.** Each tenant's rows and vault files would be encrypted in the backup with a tenant key kept in a keyring outside the backups; deleting the tenant destroys its key. Rejected for now:
  - **Every copy of the keyring must be destroyed too.** Backups are useless without it, so it has to be copied off the server, and those copies bring the problem back in a smaller file.
  - **Losing the keyring loses every backup.** That is a worse risk than the one it removes, for operators on shared hosting.
  - **Accounts are global, not per tenant.** A person can belong to several tenants, so per-tenant keys do not cover the `users` table and its references. Per-user keys would multiply the problem.
  - **It means redesigning the backup format** (per-tenant streams instead of per-table NDJSON, and partial restores).

  Revisit when an operator can use an external key service whose deletion is authoritative (HashiCorp Vault Transit, OpenBao, or a cloud KMS), or when a customer contract or the ANPD requires immediate unreadability of backups.
- **E. Encrypt `note_revisions.content` at rest** (Laravel `encrypted` cast with `APP_KEY`). Rejected for now:
  - the database and `APP_KEY` sit on the same server, so it adds little against the realistic threats;
  - backups are already encrypted as a whole (4a);
  - it ties revision history to `APP_KEY`: restoring under a different key would lose the history, and rotation needs `APP_PREVIOUS_KEYS`.
- **F. Encrypt the vault.** Out of scope: it changes the "plain `.md` on disk" invariant (spec §1) and needs its own decision.

### Consequences

- **Implemented in the same PR as this decision:**
  - `App\Domain\Privacy\ErasureLog` and `ErasureReplay`;
  - tombstones written last inside the `UserDeletion` / `TenantDeletion` transaction, so nothing is deleted without a tombstone;
  - `erasure-log.ndjson` inside every backup;
  - replay, `--erasure-log` and the `--force` rule in `jotter:restore`;
  - `erasure-log:prune` (nightly) and the `erasure_log` doctor check;
  - docs and the privacy-policy draft.
- `BACKLOG.md` block 4c now reads "erasure log replayed on restore". Crypto-shredding (D) stays a revisit condition.
- Operators get one new duty: rotate off-server backup copies within the stated window (30 days, `docs/hosted-operations.md`).
