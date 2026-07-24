# Backlog

Follow the ordered PR sequence in the authoritative spec. PR0–PR5 are on `main`; later PRs remain deferred:

- PR6–PR9: UI/rendering, auth providers, attachment upload, and deployment hardening.
- v1+: WebDAV, publishing, graph view, GrandpaSSOn/TaskConnect integrations, AI retrieval/MCP, and all other post-v0 work.

## Specification decisions and open items

- Q1 default adopted: nullable `notes.search_content` is a rebuildable search projection; disk Markdown remains canonical. PR4 adds the `FULLTEXT` index and search endpoint without persisting canonical note bodies.
- Q4 default adopted: nested folders are allowed within each workspace vault; every path is canonicalized and must resolve inside the workspace root before filesystem access.
- TODO(spec: Q2): Confirm `league/commonmark` plus a custom `[[ ]]` inline parser.
- TODO(spec: Q3): Confirm sanitizing Markdown both server-side and with DOMPurify in the SPA.
- TODO(spec: Q5): Confirm local sessions only in v0, with GrandpaSSOn limited to a later stub interface.
- TODO(spec: Q6): Confirm `jt` as the development verb prefix for `scripts/jt.sh` and `scripts/jt.ps1`.
- TODO(spec: PR3): Confirm the user-facing resolution policy for duplicate titles and case-insensitive wikilinks. Until specified, exact workspace-relative paths take precedence, exact unique titles resolve, and ambiguous references remain unresolved.
- TODO(spec: PR5): Confirm whether a future notes API update may rename/move a Markdown path. This PR safely updates content at the existing indexed path only.
- TODO(spec: Before PR7, define the final audit event vocabulary, required actor/request context, metadata redaction rules, access controls, and retention/deletion contract. The initial columns are structural only; security details must not be guessed.)
- TODO(spec: Before PR7, choose portable database-level append-only enforcement for `audit_log`; PR1 model guards do not cover query-builder or direct-SQL writes.)
- TODO(spec: PR3/PR5 must reject cross-workspace note-link and note-tag associations in application services; the §5 link/pivot shapes do not carry workspace keys for composite database enforcement.)
