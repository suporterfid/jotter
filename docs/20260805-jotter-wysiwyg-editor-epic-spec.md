# Jotter — WYSIWYG editor epic spec (resolves #263)

- **Date:** 2026-08-05
- **Resolves:** `BACKLOG.md` → "Needs a decision" → #263 (`docs/20260803-jotter-editor-chrome-notion-parity-audit.md` §B.1 / Part D.6 / Open Question 1)
- **Decision record:** `docs/decisions.md` → "Decision — WYSIWYG editor (Issue #263)"
- **Status:** Decision made, epic scoped, issues filed. Implementation not yet started — this PR is docs-only, per the same pattern as #278 (recorded the decision-needed state) and #309 (filed the Trello board-parity epic's issues without implementing them).

---

## 1. The question being answered

Issue #263 asked whether Notion-*feel* is the actual goal for `NoteEditor.vue`, or whether Notion-*calm* is enough via the chrome/spacing work in #250–#262 while keeping an honest Markdown editor. It named three options, ascending cost:

- **(a)** Keep the `<textarea>`, drop the Edit/Split/Preview toggle, default to a single rendered-ish view.
- **(b)** Inline WYSIWYG over Markdown (TipTap/Milkdown/ProseMirror with a Markdown serializer), preserving the Markdown-on-disk invariant. A multi-PR epic.
- **(c)** A real block model. Furthest from the current architecture, highest cost.

## 2. Decision

**Option (b).** Full rationale is in `docs/decisions.md`; the short version: Jotter's entire value proposition depends on `AGENTS.md`'s Markdown-on-disk invariant (Obsidian/WebDAV compatibility, plain-text portability, no lock-in) — option (c) puts that at risk by moving the source of truth toward a block model, and option (a) was already tried in spirit by #250–#262 and the audit's own text says no amount of that work closes the Notion gap while a monospace textarea is the primary surface. (b) is the only option that reaches real Notion parity **and** keeps the invariant intact.

### 2.1 Library choice: Milkdown, not TipTap

Both are ProseMirror-based, but they differ in what the document's canonical shape is:

- **TipTap** is JSON-first. Its document model is a ProseMirror JSON tree; Markdown in/out is a bolt-on extension you serialize through. Round-tripping arbitrary Markdown losslessly is something you build and maintain yourself.
- **Milkdown** is Markdown-first. Its core (`@milkdown/core` + `@milkdown/preset-commonmark` + `@milkdown/preset-gfm`) is built directly on `remark`, and a node's Markdown serialization is part of its spec, not an afterthought.

Given the invariant is the whole reason this is an epic and not a weekend refactor, "Markdown is the native format, not a target you serialize to" is the deciding factor. This also lines up with the frontend's existing Markdown toolchain (`marked` + GFM, `frontend/src/services/markdown.ts`) conceptually, even though Milkdown uses `remark` internally rather than `marked`.

### 2.2 What stays exactly as-is

- Markdown files on disk remain the single source of truth. Nothing in this epic changes `VaultStorage`, front-matter parsing, or the server-side `MarkdownServerRenderer.php` used for publish/export.
- `blockRegistry.ts` remains the single declarative source for Jotter's custom block types (wikilink, embed, callout, toggle, table, divider, code, to-do). The WYSIWYG editor's custom nodes are driven from it, not a second parallel definition.
- Obsidian-authored notes containing syntax Jotter doesn't model as a native node (dataview queries, `mermaid`/LaTeX fences, `^block-id` refs) must continue to round-trip byte-for-byte even though the WYSIWYG surface can't render them richly. "Doesn't render prettily" is acceptable; "silently rewrites on save" is not.

## 3. Why this is a multi-PR epic, not one PR

`AGENTS.md`: *"Follow its PR sequence. Do not implement a later PR before the current PR is merged and green."* An AST-based editor replacing a plain textarea is the highest-blast-radius surface in the app — every note a user has ever written flows through it on every save. Shipping it as one large PR means the round-trip-fidelity risk, the new-dependency risk, and the UX-default-change risk all land and get reviewed together, which is exactly the failure mode the three-pass audit process (`docs/20260729-jotter-audit.md` and friends) exists to avoid. Splitting it lets each risk get its own green CI gate before the next one is taken on.

## 4. Non-goals for this epic

- No change to the block-model question (option (c)) — explicitly rejected in §2.
- No new backend endpoints or schema changes. This is a frontend-only epic; `note_revisions`, `note_properties`, and the vault storage format are untouched.
- No decision here about realtime collaboration, offline editing, or any of the C1/C5 constraints already resolved in `docs/decisions.md` — out of scope, unaffected.

## 5. Phased breakdown

Each phase is an independently mergeable, independently revertible PR. A phase does not start until the previous phase's PR is merged and its CI is green.

| Phase | Issue | Scope | Ships a default-behavior change? |
|---|---|---|---|
| WY.1 | [#321](https://github.com/suporterfid/jotter/issues/321) | Add Milkdown as a dependency; build a Markdown ⇄ Milkdown-doc round-trip fidelity test harness against a fixture corpus covering every syntax feature `markdown.ts`/`MarkdownServerRenderer.php` special-case (wikilinks, embeds, callouts, toggles, tables, task lists, code fences incl. `mermaid`/dataview-as-opaque, `^block-id` refs, front matter). No UI change. | No |
| WY.2 | [#322](https://github.com/suporterfid/jotter/issues/322) | New `NoteEditorWysiwyg.vue`, added as a fourth `'live'` option on the existing `viewMode` toggle, additive alongside `edit`/`split`/`preview`. Standard CommonMark+GFM nodes only; Jotter-specific syntax passes through as plain text (still safe per WY.1). | No — opt-in only |
| WY.3 | [#323](https://github.com/suporterfid/jotter/issues/323) | Native nodes for `[[wikilink]]`, `![[embed]]`, callouts, toggles, tables, sourced from `blockRegistry.ts`. Re-point the existing `SlashMenu.vue` (#256) at a native slash-command trigger instead of raw-textarea string-splicing. | No — still opt-in |
| WY.4 | [#324](https://github.com/suporterfid/jotter/issues/324) | Port selection-dependent features off textarea coordinate hacks onto ProseMirror's real Selection/Range API: comment-anchoring (#261), history/restore (#157) verification. | No — still opt-in |
| WY.5 | [#325](https://github.com/suporterfid/jotter/issues/325) | Make `'live'` the default `viewMode`. Raw-source `Edit` stays reachable as an opt-in fallback (not removed). This is the PR that actually closes the Notion-feel gap #263 identified. | **Yes** |

## 6. Not yet decided (deliberately deferred, same discipline #263 itself modeled)

Fully removing the Edit/Split/Preview toggle — the audit's literal D.6b ask — is **not** committed scope in WY.1–WY.5. Keeping raw-source `Edit` reachable after WY.5 is a deliberate risk mitigation: it means the epic doesn't have to bet the entire Markdown-on-disk invariant on WY.1–WY.3's fixture corpus having caught every real-world edge case in one pass. Whether to remove the toggle entirely is a follow-up decision to make once WY.5 has run against the real production vault for a meaningful period — tracked as a future item, not filed as an issue yet, so it isn't drifted into silently (the same discipline #263's own text asked for).

## 7. Risks

- **Fidelity gaps the fixture corpus misses.** Mitigated by keeping `Edit` mode available post-WY.5 (§6) and by extending the WY.1 harness in WY.3 as native nodes are added, not just once at the start.
- **Bundle size.** Milkdown + ProseMirror is materially heavier than a `<textarea>`. Not benchmarked yet — first concrete number should come out of WY.2's PR description.
- **Custom node maintenance burden.** Every entry in `blockRegistry.ts` now needs a ProseMirror node spec in addition to its existing slash-menu/regex-preview definitions. WY.3 should keep the mapping mechanical (drive the node spec from the registry entry, don't hand-write five parallel definitions per block type).
