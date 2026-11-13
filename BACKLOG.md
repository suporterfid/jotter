# Jotter — Backlog

Deferred work only. Sequenced from `docs/20260727-jotter-roadmap-ai-agent.md` (product roadmap) within the constraints of `docs/jotter-initial-spec-and-build-plan.md` §14. Where the two disagree, the spec's §8 security constraints, §4 shared-hosting constraints, and §1 Markdown-on-disk invariant win, and the item sits in **Needs a decision** below until resolved.

Roadmap priorities 1–5 (search, nested folders, tags, backlinks, attachments) are **already delivered** — see spec §14.3. The roadmap's gap analysis lists them as missing because its baseline describes a different product; do not re-plan them.

This file previously also carried the shipped-item changelog, decision records, security-audit log, UI-audit log, and design-system tracker — six roles in one file, a structural cause of the reconciliation failures recorded in `docs/20260729-jotter-audit.md` (e.g. #141: this file simultaneously listing C1–C6 as resolved and as open blockers). Split per #208:

- **Shipped work** (all Milestones A–D, Spec Debt epics, v0/v1/v2 delivery, and the 2026-07-29 UI-audit follow-through) → `CHANGELOG.md`
- **Decision records** (C1–C6, the typed-property model decision, and future decisions) → `docs/decisions.md`
- **Security/correctness audit findings** → `docs/security-audit-2026.md`
- **Visual-identity design-system tracking** (#96–#110) → `docs/visual-identity.md`

As of 2026-08-20, every previously-tracked Milestone and Confluence-parity issue (#347–#360) is delivered or resolved. #51 (version history) and its comparison follow-up #388 are delivered and verified; #355 shipped in PR #375, #356 shipped in PR #377, #357 shipped in PR #380, #358 shipped in PR #382, and #359 shipped in PR #384; #360 was resolved as a documentation decision.

---

## Needs a decision (spec §14.5)

All roadmap/spec decisions are resolved — see `docs/decisions.md`. New decision work must add a dated decision record before implementation is planned.

- ~~**Pluggable per-workspace vault stores.**~~ **Decided 2026-11-13** (`docs/decisions.md`, "Pluggable vault stores", #434). Moved to the implementation queue: see *Pluggable vault stores epic* below.

## Current implementation queue

The roadmap's historical Phase 1 gaps are already delivered in this repository: search filters (#52), Markdown/JSON import and round-trip backup (#77/#78), templates/daily notes (#56), and version history with restore (#51). Do not create duplicate issues from the roadmap's stale gap summary; select the next new feature from a refreshed issue or audit.

## Pluggable vault stores epic (#429, planned 2026-11-13)

Plans: `docs/20261113-jotter-pluggable-vault-stores-plan.md` (D1–D10, PRs A–H), `docs/20261113-jotter-github-vault-implementation-plan.md` (GitHub phases 0–8, G1–G5, checks V1–V6) and `docs/20261113-jotter-tenant-vault-channels-research.md` (providers and costs). Order follows the owner's decision G4 (GitHub before S3). Each PR merged and green (`jt ci`) before the next. #434–#437 implemented 2026-11-13 in one PR (common base, epic #430); #438–#445 implemented 2026-11-13 in one PR (GitHub, epic #431); #446–#448 implemented 2026-11-13 in one PR (S3, epic #432). **Next:** the owner's live checks for GitHub and for S3 (below).

1. ~~**Common base — epic #430.**~~ **Implemented 2026-11-13** (see CHANGELOG).
   1. ~~#434 — PR A: dated decision in `docs/decisions.md` (D1–D10, G1–G5), amending spec §1/§2 and `docs/architecture.md`.~~
   2. ~~#435 — PR B: `VaultStore` interface + `LocalVaultStore`, refactor, contract test suite, no behaviour change.~~ Instance backup/restore, `tenant:delete` and provisioning still read the local folder directly: remote-store handling for them is #444.
   3. ~~#436 — PR C: `vault_stores` (admin API + Storage tab, encrypted credentials, SSRF guard, audit, doctor, `JOTTER_VAULT_DRIVERS`).~~ The `s3` and `github` drivers followed with #432 and #431.
   4. ~~#437 — PR E: `vault:move` engine (preflight, batched copy, SHA-256 verification, swap, HTTP 423, rollback, 7-day retention).~~
2. ~~**GitHub vault — epic #431.**~~ **Implemented 2026-11-13** (see CHANGELOG). Code namespace is `App\Domain\Vault\Store\GitHub` (the plan said `Stores/GitHub`; it follows the common base).
   1. ~~#438 — Phase 2: validation spike.~~ Answered from docs and the test simulator in `docs/20261113-jotter-github-vault-spike.md`, with `scripts/github-vault-spike.sh`.
   2. ~~#439 — Phase 3 (PR F1): GitHub App + fine-grained PAT, install flow, read-only probe.~~
   3. ~~#440 — Phase 4 (PR F2): read driver, blob cache, `vault:pull`, `push` webhook.~~
   4. ~~#441 — Phase 5 (PR G1): write-behind journal, `vault:flush`, write budget, sync badge.~~
   5. ~~#442 — Phase 6 (PR G2): conflicts and external changes (D8).~~
   6. ~~#443 — Phase 7 (PR G3): `vault:move` local ↔ GitHub.~~
   7. ~~#444 — Lifecycle and LGPD with a remote vault.~~
   8. ~~#445 — Phase 8 (PR H, GitHub part): docs, privacy policy draft, gradual rollout.~~
   9. **Owner action (P1, before opening GitHub to everyone):**
      - run `jt ci` and `jt release` / `release:verify` on the PR (Docker was not available where it was built);
      - with a real App and a test repository, run the live checks V1–V8 of the spike doc, including G5: an external push visible in ≤ 10 s with the webhook;
      - then turn it on for one or two tenants with `JOTTER_GITHUB_BETA_TENANTS`.
   10. Follow-ups (P3): an opt-in `Co-authored-by` trailer from the user profile (G2; off is the safe default); a commit-count estimate in the move preflight (moves only get delayed by the budget, never fail).
3. ~~**S3-compatible vault — epic #432.**~~ **Implemented 2026-11-13** (see CHANGELOG). Code namespace is `App\Domain\Vault\Store\S3`.
   1. ~~#446 — PR D: S3 driver (`async-aws/s3`) + MinIO in `compose.ci.yaml`.~~ There is no "move folder" feature in the app, so the plan's "folder move over N objects becomes a cron job" has nothing to apply to: the store moves one object at a time.
   2. ~~#447 — S3 in `vault:move`, backup rule by bucket versioning, LGPD in the bucket.~~
   3. ~~#448 — PR H (S3 part): guides and operations docs.~~ Validated against an in-memory S3 API and a local S3 server (moto); real providers still pending (below).
   4. **Owner action (P1, before offering S3 to tenants):**
      - run `jt ci` (it now starts MinIO and runs `S3VaultStoreMinioContractTest` against it) and `jt release` / `release:verify`, and note the release ZIP size (≈ +6.6 MB measured with `zip -r`);
      - run `S3VaultStoreMinioContractTest` against real AWS S3 and Cloudflare R2 with throwaway buckets (`JOTTER_TEST_S3_*`, see `docs/guia-conectar-s3.md`), then update its *Provedores testados* table;
      - GCS stays unsupported until the same run passes against it with HMAC keys.
   5. Follow-ups (P3): a storage-screen hint for the bucket's versioning and lifecycle (the API already returns `settings.bucket_state`); a shorter reindex interval for S3 workspaces if the hourly `vault:reindex --all` is too slow for edits made directly in the bucket (an S3 event notification is the push equivalent of the GitHub webhook).
4. **Later — #433 (on hold).** GitLab/Forgejo, monorepo, Git LFS, native GCS, mirror replica, 3-way merge, and the research ideas (backup destination, Drive export, SFTP, "Cofre BR", KMS crypto-shredding, Object Lock, attachment offload). Each needs its own decision record first.

## Brain multi-agent epic (spec 2026-11-03)

Spec: `docs/superpowers/specs/2026-11-03-jotter-brain-multiagent-design.md`. Plan: `docs/superpowers/plans/2026-11-03-jotter-brain-multiagent.md` (verifies the spec against `d53653a` and adds 13 corrections, G1–G13). Reopens the "write tools deferred" stance of `docs/mcp.md`; per this file's rule, PR 1 records the dated decision (D1–D8) in `docs/decisions.md` before any code. Sequence (each PR merged and green before the next):

1. ~~**Scoped credentials (R2, P1, security).**~~ **Delivered** (2026-11-03, see CHANGELOG). `CredentialContext`, `ResolveMachineToken` + `CredentialScopedIdentityProvider` decorator (MCP works under `oidc`/`grandpasson`), `EnforceCredentialAudience` (`mcp:*`/`api:*`, never `/api/admin|auth|user/*`), workspace allowlist (mandatory for admin-owned tokens), CLI + admin UI. **BREAKING:** existing tokens become `mcp:read` only.
2. ~~**MCP protocol polish (R4, P1).**~~ **Delivered** (2026-11-03, see CHANGELOG). `WWW-Authenticate`, `2025-11-25`, `MCP-Protocol-Version`/`Origin` checks, tool `annotations`, scope-filtered `tools/list`, dynamic `instructions`, per-token rate limits (429 + `Retry-After`).
3. ~~**Scoped write tools (R3, P1).**~~ **Delivered** (2026-11-03, see CHANGELOG). `create_note`, `append_to_note`, `update_note` confined by `write_paths` (own `**` glob matcher), per-note lock, disk-hash optimistic concurrency, `mcp.note_written` audit; REST `base_sha256` → 409 with SPA conflict dialog.
4. ~~**Brain tools + vault pack (R5 + R6, P2).**~~ **Delivered** (2026-11-04, see CHANGELOG). Not done: `tenant:provision --pack=brain` (optional in the plan); run `brain:init` after provisioning instead. `get_brain_context` (≤ 24 KB), `list_recent_changes`, `get_outgoing_links`, OpenAI-format `search`/`fetch`, idempotent `brain:init`, `templates:pack --pack=brain`.
5. ~~**Embedded OAuth 2.1 server (R1, P1, off by default).**~~ **Delivered** (2026-11-04, see CHANGELOG). The Administration → Connected apps tab and the consent return after a GrandpaSSOn sign-in are delivered too (2026-11-04), with a fix for OAuth tokens of GrandpaSSOn users. A Playwright E2E of the consent screen is delivered too (2026-11-06, `frontend/e2e/oauth-consent.spec.ts`). Remaining: a live check against Claude web and ChatGPT (PR 6 checklist). RFC 9728/8414 metadata, DCR, PKCE S256 authorize + consent, rotating refresh with reuse revocation, revoke, Connected apps, `oauth:prune`.
6. ~~**Client docs + doctor.**~~ **Delivered** (2026-11-04, see CHANGELOG); the live smoke test against the real deployment is the operator checklist in the PR 6 description. Five-client setup in `docs/mcp-clients.md`, snippets for Codex/Hermes, `jotter:doctor` OAuth checks, manual smoke test against the real deployment.

~~Follow-ups filed as issues~~ **Delivered 2026-11-06** (see CHANGELOG): WebDAV `ETag`/`If-Match`, so Obsidian writes get the same conflict protection ([#410](https://github.com/suporterfid/jotter/issues/410)); Client ID Metadata Documents in the OAuth server ([#411](https://github.com/suporterfid/jotter/issues/411)); opt-in semantic search over a rebuildable embeddings projection ([#412](https://github.com/suporterfid/jotter/issues/412), decision in `docs/decisions.md`); and manual MCP tokens for GrandpaSSOn users ([#414](https://github.com/suporterfid/jotter/issues/414)). Still open: check whether Remotely Save sends `If-Match`, and a live run of semantic search against a real provider.

## Triage #390 (2026-08-31) — delivered 2026-11-08

- ~~Self sign-up~~ **Delivered:** opt-in sign-up with administrator approval (`JOTTER_SIGNUP_ENABLED`).
- ~~Storage quota~~ **Delivered:** per-workspace quota, default 1 GiB, warning at 80%, 507 on growth.
- ~~Backup~~ **Delivered:** `jotter:backup` / `jotter:restore` and the runbook in `docs/deployment.md`.
- **Terms and privacy:** drafts in `docs/legal/` waiting for the owner's review; publishing them is configuration (`JOTTER_BRAND_TERMS_URL` / `JOTTER_BRAND_PRIVACY_URL`).
- ~~Licence strategy~~ **Decided:** stays MIT (`docs/decisions.md`).
- ~~Queue worker~~ **Documented:** concurrency and large imports in `docs/deployment.md`.
- ~~**Follow-up (LGPD, P2):** no way to correct one's name or delete an account.~~ **Delivered 2026-11-09:** Administration → Users → Delete (and `user:delete`) removes the account, memberships, tokens, grants, ACL entries and identities, and anonymises comments, revisions and audit entries; "Edit profile" lets people correct their name (local sign-in).

## LGPD compliance on shared hosting (2026-11-09)

Gaps found in a code review against the LGPD, the Marco Civil da Internet and the ANPD resolutions. Not legal advice: the texts in `docs/legal/` still need a lawyer. Four blocks, in this order:

1. ~~**Block 1 — low effort, high legal risk (P1).**~~ **Delivered 2026-11-09** (see CHANGELOG):
   - ~~Access records for 6 months (Marco Civil art. 15).~~ `audit:prune` keeps access records (sign-in, sign-out, OAuth tokens, MCP connections, with IP) for `JOTTER_ACCESS_LOG_RETENTION_DAYS` and everything else for `JOTTER_AUDIT_RETENTION_DAYS`. The default keeps self-hosting unchanged (both 90 days); a hosted operator sets 184. `jotter:doctor` warns when published Terms/Privacy texts meet a shorter window.
   - ~~Versioned acceptance of the Terms/Privacy texts.~~ `users.terms_version` + `JOTTER_TERMS_VERSION`; a banner asks again when the version changes (`POST /api/auth/terms/accept`, audited as `user.terms_accepted`).
   - ~~Secure production defaults.~~ `jotter:doctor` warns about `SESSION_SECURE_COOKIE`, `SESSION_ENCRYPT`, `SESSION_HTTP_ONLY` and a `single` log channel that never rotates; `.env.example` turns session encryption on and documents `LOG_STACK=daily`.
2. **Block 2 — documents and contracts (P1, operator work, no code).** Publish the reviewed Terms/Privacy drafts; sign a data processing agreement (contrato de operador, LGPD art. 39) with the hosting and SMTP providers; prefer a datacenter in Brazil or record the international-transfer safeguard (Resolução CD/ANPD nº 19/2024); keep a simplified record of processing (art. 37; small-agent regime, Resolução CD/ANPD nº 2/2022); write the incident response plan (notify ANPD and holders within 3 working days, keep the incident register for 5 years, Resolução CD/ANPD nº 15/2024); name the encarregado or a contact channel.
3. ~~**Block 3 — data subject self-service (P2).**~~ **Delivered 2026-11-10** (see CHANGELOG):
   - ~~"My data".~~ Your profile → Your data: `GET /api/user/data-export` (JSON download of everything kept about the person, no secrets and no note bodies; 5 per hour) and `POST`/`DELETE /api/user/deletion-request` (administrators e-mailed; Administration → Users lists the requests; `jotter:doctor` warns after 15 days). The deletion itself stays with an administrator (`user:delete`).
   - ~~`tenant:delete {slug}`.~~ Replaces the `tinker` runbook: `--dry-run`, `--export-to`, deletes accounts that belong only to the tenant, keeps access records detached for the Marco Civil window, removes vault folders only inside `VAULT_BASE_PATH`, audited as `tenant.deleted`.
4. **Block 4 — stronger security (P2).** Split into three PRs:
   - ~~**4a. Encrypted instance backups.**~~ **Delivered 2026-11-10** (see CHANGELOG): `JOTTER_BACKUP_KEY` (from `jotter:backup-key`) encrypts every `jotter:backup` archive as a whole (libsodium secretstream, `*.zip.enc`), `jotter:restore` decrypts it, rotation through `JOTTER_BACKUP_PREVIOUS_KEYS`, and the `backup_encryption` doctor check.
   - ~~**4b. TOTP two-step verification.**~~ **Delivered 2026-11-10** (see CHANGELOG): pure-PHP RFC 6238, encrypted secret and hashed recovery codes, a second sign-in step with replay protection and attempt limits, `JOTTER_2FA_REQUIRED_FOR_ADMINS`, `user:two-factor-reset`, and the `admin_two_factor` doctor check. Not covered: a Playwright end-to-end of the whole flow (component and API tests cover it).
   - ~~**4c. Erasure from backups.**~~ **Decided and delivered 2026-11-12** (see `docs/decisions.md` and CHANGELOG): an erasure log of deleted tenants and accounts, copied into every backup and replayed by `jotter:restore`, so a restore never brings deleted data back. Crypto-shredding, revision encryption and vault encryption stay out, with revisit conditions in the decision. **Operator duty:** rotate off-server backup copies within 30 days.
   - Follow-up of 4a: `tenant:export` archives handed to customers are still plain ZIPs. A password-protected (AES-256 ZIP) option would let customers open them with 7-Zip.

Seen while doing block 3, not yet explained: one full `jt test` run failed `TenantProvisionCommandTest::test_provision_creates_everything_and_prints_the_password_once` with `JsonException: Syntax error` (the command's `--json` output did not parse). The test passed alone, together with the other tenant command tests, and in the next full run. It may be order- or timing-dependent around the nested `platform:bootstrap-admin` Artisan call that replaces the output buffer (see the comment in the test). Capture the raw output the next time it fails.

Found while doing block 1, out of its scope: the sign-up form in `LoginModal.vue` joins the terms/privacy links with a `<template>` whose surrounding spaces Vue may collapse ("termos de usoe apolítica"); `TermsBanner.vue` uses explicit spaces instead.

## Dependency hygiene

- ~~**Production advisories (2026-11-10).**~~ **Fixed** (see CHANGELOG): `composer audit` and `npm audit --omit=dev` are clean.
- **Development-only advisories (P3).** Six moderate ones remain in `eslint` 10 (`@eslint/config-array` → `minimatch`) and in `@vue/test-utils` → `js-beautify` (`editorconfig`, `glob`, `minimatch`). None ships in the release ZIP. Re-run `./scripts/jt.sh npm audit` when `eslint` or `js-beautify` publish fixes.
- **Routine (P3).** No automated dependency alerting runs since GitHub Actions was removed: run `./scripts/jt.sh composer audit` and `./scripts/jt.sh npm audit --omit=dev` before each release, or add them to `jt ci`.
- **`vue-i18n` 11 warns about HTML in a message** (`linkReportViewer.noBrokenLinks`: `No broken <code>[[wikilinks]]</code> found.`). A dev-console warning only; move the markup out of the message when that screen is next touched.

## End-to-end suite flakiness (seen 2026-11-10)

- **`password-management.spec.ts:4` times out intermittently (P2).** Within a full `jt e2e` run, the click on `[data-testid="change-password-btn"]` waits 60 s because `<div class="tab-strip tab-strip-vertical">` in `main.main-content` intercepts pointer events. The vertical tab strip overlaps the sidebar footer. It passes alone and in some full runs, and it happened on a branch without the two-step verification code (the dependency update), so it depends on state or order left by earlier specs. Find which spec leaves the vertical tab strip open, and check whether the overlap is also a real layout bug at that viewport.
- **`content-approval.spec.ts:4` is flaky** (the review state still reads "Draft" when first checked) and **`oauth-consent.spec.ts` fails in the sandbox** (the redirect back to the loopback client is never captured). Both also happen on `main`.

## Operations follow-ups (from the 2026-08-31 multi-instance release)

- **Doctor thresholds.** Disk-space (100 MiB critical / 1 GiB warning) and scheduler age (5 minutes) are constants on `InstanceDoctor`; make them configurable only if a host proves them wrong.

## Hosted-mode follow-ups (from `feat/brand-and-plan`, 2026-08-31)

- **Plan gate coverage.** The 402 gate covers `workspace.write` routes, WebDAV `PUT`/`MKCOL`/`DELETE`, and import, per the issue's scope. Group, ACL, review-workflow, watch, and notification-state writes are metadata and remain allowed for read-only tenants; extend the gate if operators need a stricter freeze.
- **Hosted-mode UX.** The SPA surfaces 402 responses through the existing error paths (message text from the API). A dedicated upgrade/contact call-to-action would need the operator's `JOTTER_BRAND_SUPPORT_URL` and is not built.
- ~~**Design-token guard debt.**~~ Fixed in `fix/design-token-guard`: `NoteReviewPanel.vue` and `NotificationPreferences.vue` now use `--color-status-danger` / `--color-border`; `scripts/check-design-tokens.sh` passes on `main`.

## Completed follow-up

- ~~**Revision comparison (#388).**~~ **Delivered.** Added the ACL-protected revision comparison endpoint for revision-to-revision and revision-to-current comparisons, a bounded in-memory line diff, workspace/note isolation and 401/403/404/422 coverage, plus HistoryPanel selectors and semantic diff rendering. The restore workflow remains independent.

## WYSIWYG editor epic (decision resolved 2026-08-05 — option (b))

`docs/20260805-jotter-wysiwyg-editor-epic-spec.md` resolves the #263 decision recorded above: Notion-feel is the goal, delivered as inline WYSIWYG over Markdown (Milkdown, preserving the on-disk invariant — see `docs/decisions.md` for full rationale). Sequenced as five issues, each gated on the previous one merged and green:

- ~~**WY.1 — Markdown ⇄ Milkdown round-trip fidelity harness, no UI change (#321, M, P2).**~~ **Shipped.** `frontend/src/services/__tests__/wysiwygRoundTrip.spec.ts`; 8 known gaps (front matter, wikilinks, embeds, callouts, list/table cosmetic normalization) documented as required WY.3 scope, not silently dropped.
- ~~**WY.2 — Additive "Live" WYSIWYG view mode alongside Edit/Split/Preview (#322, L, P2).**~~ **Shipped.** `NoteEditorWysiwyg.vue` + `frontMatterGuard.ts`; front matter now safely round-trips through this mode (WY.1's most severe known gap, closed).
- ~~**WY.3 — Native nodes for wikilinks/embeds/callouts/toggles/tables + slash menu on the WYSIWYG surface (#323, L, P2).**~~ **Shipped.** `wysiwygNodes/{wikilink,embed,callout,toggle}.ts`; all four moved from KNOWN_GAP_FIXTURES to LOSSLESS_FIXTURES. Known limit: multi-paragraph toggle bodies aren't parsed (single-line form only).
- ~~**WY.4 — Port comment-anchoring and history/restore off textarea coordinate hacks (#324, M, P3).**~~ **Shipped.** `getSelectionAnchorLine()` (exact ProseMirror position mapping); fixed a real restore-into-same-note-id bug affecting all view modes, not just Live.
- ~~**WY.5 — Make "Live" the default view mode; keep raw source as an opt-in fallback (#325, M, P3).**~~ **Shipped — epic complete, resolves #263.** `viewMode` defaults to `'live'`; decided to keep `Preview` (not retire it) rather than cross into the toggle-removal non-goal below.

Fully removing the Edit/Split/Preview toggle (the audit's literal D.6b ask) is deliberately not committed scope in WY.1–WY.5 — see the spec doc §6.

## Obsidian UI-parity gaps

`docs/20260803-jotter-obsidian-ui-parity-audit.md` — findings from a UI
comparison against Obsidian, verified against `frontend/src/` post-#285.
Command palette, tag cloud/filter, collapsible sidebar, and the
right-hand drawer were already shipped and dropped from this list during
verification. All five remaining items shipped 2026-08-04 (G.4 scope A —
tab strip, single active pane — only; true split-screen, scope B, is
separate future work, blocked on pane-scoping G.1/G.5's DOM-id lookups):

- ~~No headings outline/TOC pane for the current note~~ (#286, shipped #292).
- ~~No hover preview for wikilinks~~ (#287, shipped #293).
- ~~No transclusion / `![[note]]` embeds~~ (#288, shipped #294).
- ~~No contextual/local graph per note~~ (#289, shipped #295).
- ~~No multi-pane / tabbed editing (scope A)~~ (#290, shipped #297).

## Trello board-parity gaps

`docs/20260804-jotter-trello-board-parity-audit.md` — findings from a
comparison of `CollectionsBoardView.vue` against Trello's board/card
feature set. All ten shipped:

- ~~**No drag-and-drop cards between board columns (#299, L, P1).**~~ **Shipped** (PR #310).
- ~~**No card creation from the board (#300, M, P2).**~~ **Shipped** (PR #311).
- ~~**Card face shows only title + path (#302, M–L, P2).**~~ **Shipped** — cover, due date, checklist, comments (PR #313).
- ~~**Tags/labels not surfaced or filterable on the board (#306, S–M, P2).**~~ **Shipped** (PR #312).
- ~~**No column configuration — reorder/rename/color/WIP limit/collapse (#301, L, P3).**~~ **Shipped** (PR #315).
- ~~**No multiple boards / saved views (#303, L, P3).**~~ **Shipped** (PR #314).
- ~~**No swimlanes / second grouping dimension (#304, L, P3).**~~ **Shipped** (PR #316).
- ~~**No card-level checklists distinct from note content (#305, M–L, P3).**~~ **Shipped** — checklists as a separate structure, not derived from task-list syntax (PR #317).
- ~~**No archive state / done-column automation (#307, M, P3).**~~ **Shipped** (PR #318).
- ~~**No per-card activity feed (#308, M, P3).**~~ **Shipped** (PR #319).

## Confluence-parity gaps

`docs/20260817-jotter-confluence-parity-audit.md` — findings from a
comparison against Confluence's feature set, verified against `app/`,
`frontend/src/`, `database/migrations/` and `routes/api.php` at
`dcda766`. Fourteen gaps, four of them never reported before. Filed as
#347–#360 are shipped or resolved; no Confluence-parity issue remains open.

Ordered by recommended priority, not by theme. The audit's §4 argues the
sequence; the short version is that role enforcement was the security
prerequisite and is now shipped, unblocking page-level ACLs.

- ~~**Membership roles are stored but never enforced (#347, M, P1).**~~ **Shipped.** `IdentityProvider::canWriteWorkspace()` now distinguishes `owner`/`admin`/`editor` from read-only `viewer` memberships; `workspace.write` protects every API mutation route, WebDAV write verbs enforce the same policy, and GrandpaSSOn service tokens require both the workspace audience and `kb:write`. Regression coverage covers local roles, service-token scopes, API writes, and WebDAV. Unblocks #349 and #359.
- ~~**No standard corporate SSO (#348, L, P1).**~~ **Shipped.** Opt-in `OidcIdentityProvider` behind `JOTTER_AUTH_PROVIDER=oidc` uses discovery, authorization-code + PKCE S256, `state`/`nonce` validation, stable `iss|sub` identity mapping, and JIT provisioning with `is_admin=false` and no membership. Local and GrandpaSSOn behavior remains unchanged; group/claim-to-membership mapping is out of scope.
- ~~**No per-page permissions (#349, L, P2).**~~ **Shipped in PR #365.** Added MySQL-only note ACLs with workspace groups, inherited access when no ACL rows exist, `view`/`edit` grants, centralized `NoteAccess`, 404 behavior for hidden notes, projection filtering across API/search/links/collections/tree/export/publish/sync/LLMs/MCP/WebDAV, and the frontend access panel. Markdown/front matter remains unchanged. **Unblocked by #347.**
- ~~**Note deletion is immediate and unrecoverable (#350, S–M, P2).**~~ **Shipped in PR #363.** Notes now use soft delete with a per-workspace `.trash/`, list/restore/permanent-delete API and UI, occupied-path protection, reindex exclusion, and bounded `vault:purge-trash` retention. **#351 (notifications + page watching) shipped in PR #366; #352 (email) shipped in PR #368.**
- ~~**Notifications only ever fire for `@mentions` (#351, M, P2).**~~ **Shipped in PR #366.** Added the event vocabulary (`note_commented`, `comment_reply`, `note_edited`, `note_moved`, `note_deleted`), explicit and auto page watching with persistent unwatch, ACL-aware recipient filtering, actor suppression, 60-second coalescing, reply participation, audit events, API/UI links, and regression coverage. Verification: 241 PHP feature tests and 564 Vitest tests pass; frontend build passes. **Unblocks #352.**
- ~~**No email delivery or digest (#352, M, P2).**~~ **Shipped in PR #368.** Added localized immediate/digest/off email delivery for mentions and all CF.5 events, recipient preferences with unsubscribe flow, bounded idempotent digest dispatch through `JobDispatcher`, deployment cron documentation, and regression coverage. **Unblocks #353.**
- ~~**No external content embeds (#353, M, P3).**~~ **Shipped in PR #371.** Added the registered external-embed block, empty-by-default HTTPS domain allowlist, mirrored PHP/TypeScript URL policy, sandboxed iframe rendering in server/SPA previews, link-only published/WYSIWYG output, fence/raw-iframe safety, and slash-menu insertion. Verification passed with 308 PHP tests (1 skipped), 577 Vitest tests, the frontend build, PHP lint, and `git diff --check`. **#354 follows.**
- ~~**No PDF export (#354, M, P3).**~~ **Shipped in PR #373.** Added Dompdf/pure-PHP note PDFs, ACL-filtered queued workspace PDFs through `JobDispatcher` and `pdf:process-exports`, private artifacts with status/download endpoints, safe local image handling, frontend controls, deployment/TaskConnect documentation, and regression coverage. **#355 follows.**
- ~~**Public sharing is whole-workspace only (#355, M, P3).**~~ **Shipped in PR #375.** Added opaque hash-only per-note tokens, optional expiry and revocation with 404 semantics, authenticated ACL/audit management, isolated published-page rendering, token-scoped registered attachments, audit events, and note-editor controls. Verification: 330 PHP tests passed (1 skipped), 586 Vitest tests passed, frontend build, PHP lint, and `git diff --check` passed.
- ~~**No installable PWA (#356, S, P3).**~~ **Shipped in PR #377.** Added manifest and standalone install metadata, canonical theme-color handling, production-only versioned service-worker registration, static-shell caching with a generic offline fallback, release synchronization, deployment guidance, and regression coverage. The worker never caches note content, authenticated HTML, APIs, WebDAV, search, or attachments.
- ~~**No usage analytics (#357, M, P3).**~~ **Shipped in PR #380.** Added durable daily audit rollups, a bounded idempotent `analytics:rollup` command, ACL-safe workspace analytics API/dashboard, and opt-in/off-by-default `NOTE_VIEWED`; dashboards never aggregate live over `audit_log`, whose source rows remain prunable after 90 days. Plan: `docs/superpowers/plans/2026-08-19-jotter-usage-analytics.md`.
- ~~**Split-screen blocked by document-global DOM lookups (#358, L, P3).**~~ **Shipped in PR #382.** Renderer heading IDs are pane-unique, outline navigation is local to `MarkdownPreview`, `NoteEditor` no longer performs editor-global DOM lookups, and the true split view now persists independent tabs/active notes with keyboard and drag-to-split controls, isolated drawers, responsive layout, and a Playwright regression. Verification: 608 Vitest tests, frontend build, existing notes E2E, and split-screen E2E passed.
- ~~**No content approval workflow (#359, L, P3).**~~ **Shipped in PR #384.** Added the advisory per-note review workflow (`draft`, `in_review`, `changes_requested`, `approved`), reviewer assignment, audit events, stale detection after direct/WebDAV/reindex edits, API endpoints, editor review drawer, and Playwright coverage. It intentionally does not lock files, write front matter state, or gate publication. **Unblocked by #347.**
- ~~**Roadmap baseline provenance (#360, XS, P3).**~~ **Resolved in `docs/decisions.md` on 2026-08-19.** The roadmap remains a useful sequencing signal, but its current-state gap summary describes a different homonymous product; spec §14.3 governs delivered-state planning.

## Not adopted

- **Visual canvas / whiteboard** — spec §3 N3; the roadmap itself lists whiteboard parity as a non-goal for the next cycle.
- **Chat-and-files parity, full database-view breadth** — named as non-goals by the roadmap.
- **Realtime multi-user editing** — spec §3 N1, permanently out on shared hosting. Re-examined by the 2026-08-17 Confluence-parity audit and left here deliberately: it is decision **C1, resolved** in `docs/decisions.md`, so no implementation issue was filed. Reopening it requires a superseding decision entry, not a PR. If it is ever reopened, only polling fits the constraint — §4 and `AGENTS.md` rule out websockets outright, so live presence is reachable but live cursors and OT/CRDT are not.
