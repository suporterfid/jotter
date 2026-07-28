# Jotter — Backlog

Sequenced from `docs/20260727-jotter-roadmap-ai-agent.md` (product roadmap) within the constraints of `docs/jotter-initial-spec-and-build-plan.md` §14. Where the two disagree, the spec's §8 security constraints, §4 shared-hosting constraints, and §1 Markdown-on-disk invariant win, and the item sits in **Needs a decision** below until resolved.

Roadmap priorities 1–5 (search, nested folders, tags, backlinks, attachments) are **already delivered** — see spec §14.3. The roadmap's gap analysis lists them as missing because its baseline describes a different product; do not re-plan them.

---

## Delivered (not backlog — recorded so the roadmap is not re-planned against it)

- Full-text search, nested vault folders, tags + front-matter projection, backlinks, attachments — v0 §7.
- Command palette, note graph view, tag cloud, task lists, code-copy — post-v0 UI.
- WebDAV sync endpoint, workspace ZIP export, static-site publishing, `llms.txt` (AI-KB Layer 1), audit-log query, server-side CommonMark rendering + sanitization, `JobDispatcher` seam.

> **Correction:** the WebDAV adapter is a hand-rolled Laravel route handler (`app/Http/Controllers/WebDavController.php`). `sabre/dav` is **not** a dependency, despite "SabreDAV" appearing in the commit message and earlier status notes. Adopting SabreDAV proper is a separate, unplanned decision.

---

## Milestone A — knowledge foundations (near-term)

The only Phase 1 items the product does not already have.

- [ ] **Version history** — roadmap priority 6; promoted from v2 to v1 (spec §6). Revision snapshots with restore. **Storage design is constrained:** no per-revision files in the vault (spec §14.5 C6, §4 inode quotas). Decide DB-stored deltas vs. bounded snapshot retention first.
- [ ] **Search filters** — roadmap priority 1 asks for filters by title, tags, and modified date. Current search is unfiltered natural-language `FULLTEXT` matching only.
- [ ] **Markdown / JSON import** — export ships; the inbound half does not. Must route through `VaultPathGuard` and the reindex path.

## Milestone B — connected knowledge

- [ ] **Daily notes and templates** — roadmap priority 7; already scoped as v1.
- [ ] **Broken-link report** — workspace-wide report of unresolved `[[wikilinks]]`. The unresolved rows already exist in `note_links`; this is the reporting surface.
- [ ] **Typed note properties** — roadmap priority 3's missing half. Front-matter is parsed and projected, but there is no typed property layer. Gates the metadata table view.
- [ ] **Richer block / slash-command surface** — callouts, toggles, tables, dividers, embeds.
- [ ] **MCP server** — the second half of AI-KB Layer 1; `llms.txt` retrieval already ships.

## Milestone C — team collaboration

- [ ] **GrandpaSSOn identity adapter** — consume tenancy claims and RBAC. The stub and the `IdentityProvider` seam already exist.
- [ ] **Workspace administration UI** — `tenants` / `workspaces` / `memberships` with roles exist and are enforced; there is no surface for managing them.
- [ ] **Comments and mentions** — roadmap priority 9. Needs new §5 schema and §8 S5 authorization. Asynchronous only (see C1).
- [ ] **Notification / event bus** — should reuse the audit-log event infrastructure per the roadmap's dependency map.

## Milestone D — structured work

Blocked on decision **C2**. Do not begin without it.

- [ ] **Metadata table view** — roadmap priority 11.
- [ ] **Board / calendar views** — roadmap priority 12.
- [ ] **Relations and linked records.**

## v2 — later

- [ ] **Document parsing** — PDF / DOCX / PPTX → Markdown, delegated to TaskConnect.
- [ ] **Web crawler** — web page capture to Markdown.
- [ ] **Embeddings and RAG** — semantic search over the vault.

---

## Visual identity (cross-cutting)

Not a roadmap item and not blocked on any §14.5 decision. Adopts a shared dark/purple design system — semantic tokens, Open Sans, WCAG 2.2 AA — across the SPA, the Laravel shell, and the published static site. Presentation layer only: no API contract, no change to the Markdown-on-disk invariant (spec §1), no change to §8 security requirements.

Tracked in **#96**. Today the product ships four unrelated visual treatments (SPA glassmorphism, a light-serif landing stylesheet, the stock Laravel welcome page, and an unstyled published site), three sub-AA color pairs, six `outline: none` sites with no replacement focus indicator, and no `prefers-reduced-motion` handling.

- [ ] Foundation — #97 spec + asset structure, #98 token layer, #99 self-hosted Open Sans.
- [ ] Application — #100 component migration, #101 typography, #102 spacing/shape/elevation, #103 controls and focus, #104 motion.
- [ ] Other surfaces — #105 theme reconciliation, #106 published static site, #107 app-shell metadata, #108 project mark.
- [ ] Verification — #109 WCAG 2.2 AA audit (acceptance gate), #110 CI token guard (lands last).

---

## Needs a decision (spec §14.5)

These block the roadmap items above. Until each is answered, the constraint wins.

- **C1 — Collaboration model.** Roadmap Phase 3 lists presence indicators; its baseline assumes realtime collaboration. Spec §3 N1 and §4 exclude both — no websockets on shared hosting. `TODO(spec): confirm collaboration stays asynchronous — comments and mentions yes, presence and live cursors no.`
- **C2 — Structured collections.** Roadmap Phase 4 wants database-like collections over note metadata. Spec §1 says MySQL is only an index. `TODO(spec): decide whether collections project from front-matter on disk, or whether the Markdown-on-disk invariant is being amended.`
- **C3 — Synced blocks.** Roadmap priority 8. Transclusion that does not degrade to readable Markdown breaks Obsidian compatibility. `TODO(spec): choose a plain-Markdown-safe syntax, or drop the item.`
- **C5 — Offline / mobile-first.** Roadmap Phase 5 differentiators contradict §2's deliberate local-first inversion. `TODO(spec): confirm WebDAV + Obsidian is the mobile and offline answer.`
- **C6 — History storage.** See Milestone A. `TODO(spec): DB deltas or bounded snapshots; no per-revision files in the vault.`
- **Roadmap baseline provenance.** `TODO(spec): confirm whether the roadmap's gap analysis was drawn from a different product of the same name. Until confirmed, spec §14.3 governs what counts as delivered.`

## Not adopted

- **Visual canvas / whiteboard** — spec §3 N3; the roadmap itself lists whiteboard parity as a non-goal for the next cycle.
- **Chat-and-files parity, full database-view breadth** — named as non-goals by the roadmap.
- **Realtime multi-user editing** — spec §3 N1, permanently out on shared hosting.
