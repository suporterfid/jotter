# Jotter — Project Status

- **Current Version:** v0.9.0-prod (v0 Spec Complete & Verified + v1 Enhancements)
- **Last Updated:** 2026-07-27
- **Repo:** https://github.com/suporterfid/jotter
- **Production Site:** https://hub.taskconnect.com.br/
- **CI Status:** 🟢 100% Green (`59/59 PHPUnit`, `7/7 Vitest`)

---

## 1. Accomplished (v0 Spec & Beyond)

- **PR0 — Scaffold & CI**: Laravel 12 + Vue 3 SPA + Vite + Docker dev + `jt` scripts + release target.
- **PR1 — Data Model & Migrations**: Idempotent schema (`tenants`, `workspaces`, `notes`, `note_links`, `tags`, `note_tags`, `attachments`, `users`, `audit_log`).
- **PR2 — Vault Storage Service**: Plain `.md` files on disk, frontmatter parsing, `VaultPathGuard` path traversal protection, `vault:reindex` Artisan command.
- **PR3 — Links & Backlinks**: `[[wikilinks]]` parsed into `note_links` table, real-time incoming link resolution.
- **PR4 — Full-Text Search**: MySQL `FULLTEXT` indexing over note title and content (`GET /api/workspaces/{id}/search`).
- **PR5 — Workspace Notes CRUD API**: Scoped notes endpoints with authorization enforcement.
- **PR6 — Frontend Vue 3 SPA**: Glassmorphism UI, Markdown editor, `[[` wikilink autocomplete, backlinks panel.
- **PR7 — Auth Abstraction**: `IdentityProvider` domain seam with `LocalIdentityProvider` and live `GrandpaSSOnIdentityProvider` adapter.
- **PR8 — Attachment Management**: File uploads to vault `_resources/` with 20MB type/size allowlist and streaming endpoints.
- **PR9 — Deployment Hardening**: Hostinger shared hosting combo deployment, AutoSSL, production `.env` configuration.
- **PR10 to PR40 — Post-v0 Enhancements**:
  - Global Command Palette (`Cmd+K` / `Ctrl+K`)
  - Drag & Drop Uploads & Status Bar
  - Interactive GFM Task Lists & Code Block Copy Button
  - Sidebar Tag Cloud Explorer & Multi-Criteria Sorting
  - Interactive Note Relationship Graph View (`GraphView.vue`)
  - WebDAV / REST Sync Endpoint (`GET /api/workspaces/{id}/sync`)
  - Workspace Vault ZIP Archive Export (`GET /api/workspaces/{id}/export`)
  - Audit Log & Revision Query Endpoint (`GET /api/workspaces/{id}/audit-logs`)
  - Server-Side CommonMark Renderer & XSS Sanitizer (`MarkdownServerRenderer.php`)
  - Background Job Dispatcher Seam (`JobDispatcher` + `LocalJobDispatcher`)

---

## 2. Next Milestones (v1 Roadmap)

- SabreDAV / WebDAV protocol adapter for Obsidian mobile direct sync.
- Workspace Publishing as a static site.
- AI-KB Layer 1 (Retrieval API + `llms.txt`) and MCP Server.
