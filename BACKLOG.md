# Backlog

Follow the ordered PR sequence in the authoritative spec. PR1 deliberately defers all behavior built on top of the data model:

- PR2: path-safe vault reads/writes, front-matter handling, incremental projection updates, and reconcile.
- PR3: wikilink parsing, resolution, and backlinks.
- PR4: the MySQL `FULLTEXT` index, query behavior, ranking, snippets, and search endpoint.
- PR5–PR9: notes CRUD, UI/rendering, auth providers, attachment upload, and deployment hardening.
- v1+: WebDAV, publishing, graph view, GrandpaSSOn/TaskConnect integrations, AI retrieval/MCP, and all other post-v0 work.

## Specification decisions and open items

- Q1 default adopted: nullable `notes.search_content` is a rebuildable search projection; disk Markdown remains canonical. Creating its `FULLTEXT` index and implementing search remain deferred to PR4.
- TODO(spec: Q2): Confirm `league/commonmark` plus a custom `[[ ]]` inline parser.
- TODO(spec: Q3): Confirm sanitizing Markdown both server-side and with DOMPurify in the SPA.
- TODO(spec: Q4): Confirm nested folders within each workspace vault, guarded by canonical path validation.
- TODO(spec: Q5): Confirm local sessions only in v0, with GrandpaSSOn limited to a later stub interface.
- TODO(spec: Q6): Confirm `jt` as the development verb prefix for `scripts/jt.sh` and `scripts/jt.ps1`.
- TODO(spec: Before PR7, define the final audit event vocabulary, required actor/request context, metadata redaction rules, access controls, and retention/deletion contract. The initial columns are structural only; security details must not be guessed.)
- TODO(spec: Before PR7, choose portable database-level append-only enforcement for `audit_log`; PR1 model guards do not cover query-builder or direct-SQL writes.)
- TODO(spec: PR3/PR5 must reject cross-workspace note-link and note-tag associations in application services; the §5 link/pivot shapes do not carry workspace keys for composite database enforcement.)
