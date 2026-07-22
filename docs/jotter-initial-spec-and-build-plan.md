# Jotter — Initial Spec & First-Implementation Build Plan

> Your pocket notebook — self-hosted, on the cPanel your grandpa never gave up.

- **Status:** Draft for implementation (green-field / empty repo)
- **Audience:** Cursor Cloud Agent (autonomous) planning and executing the first implementation in `suporterfid/jotter`
- **Repo:** https://github.com/suporterfid/jotter — currently empty
- **License:** MIT
- **Companion specs (siblings, not hard dependencies for v0):**
  - `grandpasson-spec-v1-extension.md` — identity (SSO, tenancy claims, machine tokens)
  - `taskconnect-spec-v1-extension.md` — background jobs (idempotent, workspace-scoped, pipelines)
- **One-line description (repo About):** Self-hosted, Markdown knowledge base for the cPanel your grandpa never gave up. Plain `.md` files, PHP + MySQL, your notes stay yours.

---

## 0. How the Cursor Cloud Agent should use this document

This is the **planning + execution authority** for v0. Operate as follows:

1. **This is a green-field repo.** The first job is scaffolding (PR0, §11). Do not write feature code before the scaffold, CI, and dev loop exist.
2. **Ship in the PR sequence in §11, in order.** One PR per unit. Do not start PR*n+1* until PR*n* is merged with green CI and its Definition of Done met.
3. **Definition of Done (every PR):**
   - Code compiles/boots; `jt up` works; `jt test` (unit + integration) green; Playwright E2E green where a UI surface is touched.
   - Migrations are **idempotent** (safe to re-run).
   - No secret/credential is hardcoded — all config from `.env` (§8).
   - `jt release` still emits a working shared-hosting zip under `dist/` with **no secrets** in it.
   - Docs updated (`README.md` and/or `docs/`), and `STATUS.md` reflects what shipped.
4. **Stay inside v0 scope (§6).** SHOULD/LATER items are out of scope for this run. If you finish v0, **stop at the §12 stop line and request review** — do not begin v1.
5. **Design for the seams, don't build the neighbors.** Auth and background jobs are abstracted behind interfaces (§9). Implement the local/default path; leave the GrandpaSSOn/TaskConnect adapters stubbed behind the same interface. Do not implement GrandpaSSOn or TaskConnect features here.
6. **When ambiguous,** consult §13 Open Questions; if unresolved, leave `TODO(spec): <question>` and take the safest documented default. Never weaken path-traversal, XSS, or secret handling to make a test pass.
7. **Working artifacts:** maintain `STATUS.md` (what's done / in progress / next) and `BACKLOG.md` (deferred items) at the repo root, updated each PR. These are the accountability layer.

---

## 1. Mission — what Jotter is

Jotter is a **self-hosted, Obsidian-compatible Markdown knowledge base** that runs on cPanel-style shared hosting (PHP + MySQL). The vault is **plain `.md` files on disk**; the app puts backlinks, full-text search, multi-workspace organization, publishing, and AI-agent access on top — without taking your data hostage.

**The defining design decision:** the source of truth is a folder of Markdown files, not a database. The database is only an *index*. This makes Jotter interoperable — desktop/mobile Obsidian can point at the same vault (via WebDAV, a v1 feature) — and durable: if Jotter disappears, you still have a readable folder of notes.

Jotter is one of three small self-hosting tools: **Jotter** (knowledge), **GrandpaSSOn** (identity), **TaskConnect** (background jobs). It must run usefully on its own; the other two make it better, not possible.

---

## 2. Architecture & stack decisions

- **Backend:** PHP 8.2+, **Laravel 12** (modular monolith). Chosen to match `taskconnect` so deploy-by-zip, `public/` web root, Artisan commands, migrations, and the Docker dev loop are shared muscle memory. (GrandpaSSOn's framework-less style is fine for a tiny broker; Jotter's feature surface justifies a framework.)
- **Frontend:** **Vue 3 SPA** built with **Vite**, in `frontend/` — same as `taskconnect`.
- **Data:** **MySQL 8.0+** for the *index* (notes metadata, links, tags, search) + app state. **The vault itself is `.md` files on disk**, never in the DB.
- **Search:** MySQL `FULLTEXT` (InnoDB). No dependency on SQLite FTS5 (not guaranteed on shared hosts).
- **Markdown:** server-side render with a maintained PHP library (e.g. `league/commonmark`) **plus sanitization**; client-side live preview mirrors it. Wikilink (`[[...]]`) is a custom extension.
- **Background work:** none in-process for anything slow. v0 uses a single Artisan reconcile command runnable by cron; heavier deferred work is delegated to TaskConnect later (§9).
- **Deploy:** zip → shared hosting, document root `public/`, HTTPS via AutoSSL. Docker only for dev.

**The local-first inversion (why this is not a literal Obsidian clone):** Obsidian is local-first with no server; shared hosting is request/response with no daemon. Jotter cannot replicate Obsidian's runtime — it replicates its **data model** (Markdown-on-disk) and adds a server. Hence "Obsidian-compatible," not "Obsidian clone."

---

## 3. Non-goals (v0 — explicit, prevents scope creep)

- **N1 — No real-time collaboration / live multi-user editing.** Shared hosting has no websockets. Single-writer assumptions for v0.
- **N2 — No in-process heavy compute.** Document parsing (PDF/DOCX/PPTX/XLSX), website crawling, and embeddings are **not** in v0 (they're v2, delegated to TaskConnect).
- **N3 — No plugin system, canvas, version-history UI, or PWA in v0.**
- **N4 — Jotter does not implement identity or a job scheduler.** It integrates with GrandpaSSOn and TaskConnect behind interfaces (§9).
- **N5 — Publishing, WebDAV, graph view, and the AI-KB/MCP layer are v1**, not v0 (data model must not preclude them).

---

## 4. Shared-hosting constraints (the walls)

Design every feature inside these. Verify on the target Hostinger plan.

- **No long-running processes / no websockets.** Anything "live" is polling or deferred.
- **`exec`/`shell_exec`/`proc_open` are typically disabled.** No shelling to `git`, `ripgrep`, `pandoc`, or `libreoffice`. Pure PHP only.
- **PHP execution limits** (`max_execution_time`, `memory_limit`, `upload_max_filesize`). Any operation that could exceed them (full reindex, future conversions) runs as a bounded Artisan command / delegated job, never a web request.
- **Inode & disk quotas.** A vault is thousands of tiny files. Do not create incidental per-note cache files that multiply inode usage; prefer DB-cached rendered output.
- **Cron is the only scheduler**, ~1-minute granularity (some budget plans throttle). v0's `vault:reindex` reconcile must be cron-runnable and bounded.
- **Deploy = zip + migrations; `public/` web root; HTTPS via AutoSSL.**

---

## 5. Domain model

Bake the full hierarchy in from day one even though v0 runs effectively single-tenant/single-user — retrofitting tenancy later is painful.

```
Tenant ──< Workspace ──< Note
                    ├──< Attachment
                    └──< (later) PublishedSite
Note ──< NoteLink (backlinks)      Note >──< Tag (note_tags)
User/Identity ──(membership)── Tenant / Workspace
```

Initial tables (v0):

| Table | Purpose |
|-------|---------|
| `tenants` | org/account (single row in v0 is fine) |
| `workspaces` | vault namespace: `id`, `tenant_id`, `slug`, `name`, `vault_path` (disk root), `created_at` |
| `notes` | index only: `id`, `workspace_id`, `path` (relative, in-vault), `title`, `frontmatter` (JSON), `content_hash`, `updated_at`. **No note body stored** beyond what search needs. |
| `note_links` | `source_note_id`, `target_ref` (raw `[[...]]`), `target_note_id` (nullable if unresolved), `type` |
| `tags` | `id`, `workspace_id`, `name` |
| `note_tags` | `note_id`, `tag_id` |
| `attachments` | `id`, `workspace_id`, `path`, `mime`, `size`, `created_at` |
| `users` / `identities` | local users in v0; provider-agnostic subject id ready for GrandpaSSOn |
| `memberships` | `subject_id`, `tenant_id`, `workspace_id?`, `role` |
| `audit_log` | append-only security-relevant events |
| `search_index` | either a `FULLTEXT` column on `notes` or a dedicated table (agent's call; document it) |

> The vault path is the source of truth. `notes` and friends are a rebuildable projection of the files on disk.

---

## 6. Scope by release (MoSCoW)

### v0 — Must have (this run)
Vault store on disk · index in MySQL · wikilinks + backlinks · full-text search · Markdown editor + safe render · workspace-scoped notes CRUD · **auth abstraction with a working local provider** · attachment upload · deploy-by-zip + reconcile command. **Multi-workspace data model present** (even if one workspace is used).

### v1 — Should have (next run, out of scope now)
WebDAV endpoint (SabreDAV) for Obsidian sync · graph endpoint · GrandpaSSOn identity adapter (tenancy claims, RBAC) · publishing a workspace as a static site · AI-KB **Layer 1** (retrieval API + `llms.txt`) and **MCP server** · daily notes/templates · orphan/broken-link report · TaskConnect delegation for reconcile/publish.

### v2 — Later
Document parsing (PDF/DOCX/PPTX/XLSX → MD) · website crawling → MD · embeddings/RAG · PWA/offline · version history/diffs · plugins · canvas.

### Won't (this iteration)
Anything in v1/v2, real-time collab, non-MySQL backends.

---

## 7. v0 feature contracts & acceptance criteria

### 7.1 Vault storage (source of truth)
Read/write `.md` on disk, per-workspace root, YAML front-matter parsed (Symfony YAML). **Every path is validated against the workspace root — no traversal.** On write, update the index incrementally; a bounded `php artisan vault:reindex --workspace=<id>` reconciles fully.

```
Given a note path that resolves outside the workspace vault root (e.g. "../../etc/passwd"),
When any read/write is attempted,
Then it is rejected before touching the filesystem and the attempt is audited.

Given a .md file edited directly on disk (out of band),
When vault:reindex runs,
Then the index reflects the new title, front-matter, tags, and links.
```

### 7.2 Links & backlinks
Parse `[[note]]`, `[[note|alias]]`, `[[note#heading]]`. Resolve to `target_note_id` where possible; keep unresolved refs. Backlinks are a query (`WHERE target_note_id = ?`), never a filesystem scan.

```
Given note A contains [[B]] and note B exists,
When A is saved,
Then B's backlinks include A.

Given note A links [[C]] where C does not exist,
When A is saved,
Then the link is stored unresolved and surfaces in a future broken-link report (v1) without erroring.
```

### 7.3 Search
MySQL `FULLTEXT` over note content/title. A `/api/workspaces/{id}/search?q=` endpoint returns ranked matches with snippets.

### 7.4 Notes CRUD API (workspace-scoped)
`GET/POST/PUT/DELETE /api/workspaces/{id}/notes[/{noteId}]`. All operations authorized (§7.6) and scoped to the workspace.

### 7.5 Editor & safe render
Vue SPA: workspace/file browser, Markdown editor with live preview, `[[` autocomplete driven by the index, and a backlinks panel. **Rendered Markdown is sanitized** (server-side sanitize and/or DOMPurify) — user content must not inject script.

```
Given a note whose body contains a <script> tag or a javascript: URL,
When it is rendered in the preview or reader,
Then the script does not execute and the dangerous markup is stripped/neutralized.
```

### 7.6 Auth abstraction (the important seam)
Define an `IdentityProvider` interface. Ship **`LocalIdentityProvider`** for v0: a bootstrap admin (`php artisan platform:bootstrap-admin <email> <password>`), sessions with `HttpOnly`/`Secure`/`SameSite` cookies, route protection. Ship a **stubbed `GrandpaSSOnIdentityProvider`** implementing the same interface, wired but disabled by env flag — no protocol code beyond the interface contract.

```
Given AUTH_PROVIDER=local and a bootstrapped admin,
When the admin logs in,
Then they receive a secure session and can access their workspace; anonymous requests to note APIs are rejected 401.

Given AUTH_PROVIDER=grandpasson (future),
When selected,
Then the app resolves identity via the GrandpaSSOnIdentityProvider adapter without any change to feature code.
```

### 7.7 Attachments
Upload respecting PHP limits; stored **outside `public/`**; streamed through the app (the vault is never directly web-served). Enforce a type/size allowlist.

### 7.8 Deploy + reconcile
`jt release` builds a zip; deployment runs migrations; `public/` is the web root; `vault:reindex` is documented as a cron entry. A test asserts **no secret ships in the zip**.

---

## 8. Security requirements (hard constraints)

Derived directly from failure modes being remediated elsewhere (open rules, hardcoded creds, fake auth).

- **S1. No hardcoded secrets.** All from `.env`; a build test fails if a credential-like literal ships in `dist/`.
- **S2. Path-traversal safety** on every vault read/write (§7.1) — canonicalize and assert prefix within the workspace root.
- **S3. XSS-safe rendering** of all Markdown (§7.5).
- **S4. The vault is never directly web-served.** Attachments and notes stream through authorized app routes only.
- **S5. Authorization on every note/attachment/workspace operation;** anonymous access denied by default (no "open rules").
- **S6. Secure sessions** (`HttpOnly`, `Secure`, `SameSite`), CSRF protection on state-changing routes.
- **S7. Pure-PHP crypto** (`sodium`/`openssl`); no shelling out.
- **S8. Audit** login success/failure, auth changes, and rejected traversal/authorization attempts.
- **S9. Upload allowlist** (type + size); reject executable/serverside content in the vault web path.

---

## 9. Cross-project interfaces & seams

Jotter defines the **interfaces**; v0 implements only the local/default side.

- **Identity — `IdentityProvider` (§7.6).** Local provider now; GrandpaSSOn adapter stubbed. When GrandpaSSOn v1 ships, the adapter consumes its `session/exchange` claims (tenant, role, groups) and validates machine tokens via introspection — with **zero** change to feature code.
- **Background jobs — `JobDispatcher` interface.** v0 has a `LocalDispatcher` (synchronous, or a bounded Artisan command run by cron for `vault:reindex`). A `TaskConnectDispatcher` is stubbed behind the same interface for v1 (workspace-scoped, idempotent submission per the TaskConnect spec). No TaskConnect protocol code in v0.
- **Rule:** never let v0 hard-depend on the neighbors. If GrandpaSSOn/TaskConnect are absent, Jotter runs fully with local providers.

---

## 10. Repo layout (target)

```
jotter/
├─ app/                  # Laravel app (Http, Domain: Vault, Index, Links, Search, Identity, Jobs)
├─ bootstrap/ config/ database/ routes/ storage/
├─ frontend/             # Vue 3 + Vite SPA
├─ public/               # web root (index.php, built assets)
├─ docker/               # dev images
├─ scripts/              # jt.sh / jt.ps1 (dev verbs)
├─ tests/                # phpunit + Playwright
├─ docs/                 # deployment.md, architecture.md, this spec
├─ .env.example  Makefile  compose.yaml  compose.ci.yaml
├─ artisan  composer.json  package.json  vite.config.js
├─ AGENTS.md  CLAUDE.md  STATUS.md  BACKLOG.md
├─ README.md  LICENSE  CHANGELOG.md
```

Dev verbs (mirror `taskconnect`'s `tc`): `jt up|down|test|e2e|artisan|composer|npm|release`.

---

## 11. First implementation plan — PR sequence

Each item is one PR. Merge in order; DoD (§0.3) applies to all.

- **PR0 — Scaffold (do this first).** Laravel 12 app + Vue 3/Vite `frontend/`; Docker dev (`compose.yaml`, `scripts/jt.*`); phpunit + Playwright config; GitHub Actions CI; `.env.example`; `AGENTS.md`/`CLAUDE.md`/`STATUS.md`/`BACKLOG.md`; `README.md` header (voice/tagline above) + MIT `LICENSE`; `jt release` → `dist/` zip target; `platform:bootstrap-admin` command; a no-secrets-in-zip test.
  *DoD:* `jt up` boots an empty app at `http://localhost:8080`; `jt test` green; `jt release` produces a zip.
- **PR1 — Data model + migrations** (§5). Idempotent. Seed one tenant + one workspace with a `vault_path`.
- **PR2 — Vault storage service** (§7.1): read/write `.md`, front-matter parse, path-traversal guard, incremental index-on-write, `vault:reindex` reconcile command.
- **PR3 — Links & backlinks** (§7.2).
- **PR4 — Search** (§7.3): `FULLTEXT` + search endpoint.
- **PR5 — Notes CRUD API** (§7.4), workspace-scoped, behind auth guard placeholder.
- **PR6 — Frontend** (§7.5): browser, editor, live safe preview, `[[` autocomplete, backlinks panel, search UI. Playwright happy-path E2E.
- **PR7 — Auth abstraction** (§7.6): `IdentityProvider` + `LocalIdentityProvider` (real) + `GrandpaSSOnIdentityProvider` (stub); protect routes; sessions/CSRF.
- **PR8 — Attachments** (§7.7).
- **PR9 — Deploy hardening** (§7.8): migrations-on-deploy, `docs/deployment.md`, security pass (traversal + XSS + secrets tests), cron reconcile documented.

**Immediate first action for the agent:** open PR0. Do not touch feature code until PR0 is merged and CI is green.

---

## 12. v0 stop line (Definition of Done for this run)

> A user can bootstrap an admin, log in (local provider), create/edit/browse Markdown notes stored as `.md` files on disk in a workspace, follow `[[wikilinks]]` and see backlinks, run full-text search, and upload attachments — with path-traversal-safe, XSS-safe, authorized-by-default behavior; the multi-workspace data model is in place; `vault:reindex` reconciles the index from disk; `jt release` ships a zip with no secrets; `jt test` + Playwright green; `STATUS.md` current.
>
> **Stop here and request review.** Do not start v1 (WebDAV, publishing, GrandpaSSOn adapter, MCP/AI-KB).

---

## 13. Open questions (resolve before/with implementation)

| # | Question | Default if unresolved | Owner |
|---|----------|-----------------------|-------|
| Q1 | Store note body in `notes` for search, or read from disk on demand + keep only a FULLTEXT projection? | Keep a FULLTEXT projection column; disk stays source of truth. | Joe |
| Q2 | Markdown lib: `league/commonmark` with a custom wikilink extension — confirm? | Yes; `league/commonmark` + custom `[[ ]]` inline parser. | Joe |
| Q3 | Sanitize server-side, client-side (DOMPurify), or both? | Both — server sanitize on render + DOMPurify in the SPA. | Joe |
| Q4 | Single vault root per workspace on disk, or nested folders allowed within? | Nested folders allowed; path always validated against workspace root. | Joe |
| Q5 | v0 auth: local sessions only (defer any GrandpaSSOn wiring to stub)? | Yes; only the stub interface lands in v0. | Joe |
| Q6 | Dev verb prefix `jt` (matches `tc`) acceptable? | Yes: `scripts/jt.sh` / `jt.ps1`. | Joe |

---

*Green-field build. Start at PR0, ship the PR sequence in order, keep CI green, and stop at the §12 stop line for review. Jotter must run fully on its own; GrandpaSSOn and TaskConnect are seams, not dependencies.*
