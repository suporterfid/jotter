# Backlog

PR0 deliberately contains no vault, notes, links, search, editor, provider abstraction, attachments, or v1 code. Follow the ordered PR sequence in the authoritative spec.

## Specification assumptions

- TODO(spec: Q1): Confirm keeping a MySQL FULLTEXT projection column while Markdown on disk remains the source of truth.
- TODO(spec: Q2): Confirm `league/commonmark` plus a custom `[[ ]]` inline parser.
- TODO(spec: Q3): Confirm sanitizing Markdown both server-side and with DOMPurify in the SPA.
- TODO(spec: Q4): Confirm nested folders within each workspace vault, guarded by canonical path validation.
- TODO(spec: Q5): Confirm local sessions only in v0, with GrandpaSSOn limited to a later stub interface.
- TODO(spec: Q6): Confirm `jt` as the development verb prefix for `scripts/jt.sh` and `scripts/jt.ps1`.

These defaults are documented only; PR0 does not implement their later feature code.
