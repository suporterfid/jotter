# Jotter — Security Audit Log (2026)

Immutable record of security/correctness audit findings and their fixes. Split out of `BACKLOG.md` (#208) so the security record isn't reconciled alongside an active backlog file. New audits append a new dated `##` section below; do not edit a closed finding's original entry.

---

## 2026-07-28 audit — authorization, path safety, identity-provider code

Found via a codebase audit of authorization, path safety, and identity-provider code. Each had a GitHub issue; worked one PR at a time.

- ~~**#145 — comment deletion had no ownership check.**~~ **Closed**, merged via PR #149. Any workspace member could delete any other member's comment. Fixed with an author-or-admin check plus regression tests.
- ~~**#146 — MCP `read_note` calls undefined `VaultStorage::getNoteContent()`.**~~ **Closed**, merged via PR #150. Fatal error on every call; fixed to call `readContents()`.
- ~~**#147 — `GrandpaSSOnIdentityProvider` creates every new SSO user as an admin.**~~ **Closed**, merged via PR #151. Both the hardcoded creation default and the null-coalesce now default to non-admin. A separate, pre-existing dead-code bug in the same method (AUTHSESSID branch's `expires_at` comparison never matches on MySQL) was found but is out of scope — noted for a future follow-up.
- ~~**#148 — no branch protection on `main` requiring green CI before merge.**~~ **Closed.** The mechanism behind #140's silent regression after #49. Fixed via `gh api` on the repo's branch protection endpoint (required status check `test`, `enforce_admins`, no force-push/delete) — a repo-settings change, not a code diff. Verified live by confirming a direct push to `main` is rejected.

---

## 2026-07-28 — CI/process gaps

Found during a review of whether `main` was 100% adherent to the roadmap and backlog. All three are closed.

- ~~**CI red on `main`: #140.**~~ **Closed**, merged via PR #144. `docker/php/entrypoint.sh` chowned `storage/app/private` for `www-data` but never `storage/app/vaults`, the default vault root (`config/jotter.php`). On a genuinely fresh checkout that directory doesn't exist yet, and the app's `mkdir()` (`VaultPathGuard.php:85`) fails with permission denied on note creation — a real 500, confirmed via diagnostics added to `notes.spec.ts` and a Laravel-log dump added to CI, and reproduced locally by resetting `storage/app` permissions to match a fresh checkout. Fixed by adding `storage/app/vaults` to the entrypoint's ownership loop; verified both ways locally (fails without the fix, passes with it) and on two green GitHub Actions runs on the PR.
- ~~**`BACKLOG.md` was self-contradictory.**~~ **Closed** (#141). The "Recorded Decisions" section marked C1–C6 resolved while the "Needs a decision" section below it still listed the same five as open blockers — added by different commits that were never reconciled. See `docs/decisions.md`.
- ~~**Dead-code cleanup from #66 was incomplete.**~~ **Closed** (#142, merged via PR #144). The six dead `withoutMiddleware(WorkspaceAuthorizationPlaceholder::class)` calls in `tests/Feature/WorkspaceNotesApiTest.php` are removed — every test in that file already authenticates as an admin, and admins bypass workspace-membership checks in `LocalIdentityProvider`, so no bypass was ever needed.

---

## 2026-07-29 audit — document coherence, spec-vs-code gaps, Obsidian compatibility

Full read-only audit report: `docs/20260729-jotter-audit.md`. Findings and their fix issues:

- ~~**Obsidian comments (`%%comment%%`) leaked into the SPA and published static sites.**~~ **Closed** (#202). Critical: content/privacy leak, not stripped by `MarkdownServerRenderer`.
- ~~**`.excalidraw.md` files indexed as regular Markdown notes.**~~ **Closed** (#203). Critical: data corruption risk in search/backlinks/SPA.
- ~~**Front-matter `aliases:` not resolved for wikilinks.**~~ **Closed** (#204).
- ~~**Block references (`^block-id`, `[[note#^block]]`) unsupported.**~~ **Closed** (#205).
- ~~**LaTeX/Mermaid rendering unsupported (acceptable degradation, made optional).**~~ **Closed** (#206).
- ~~**Plugin output (dataview, Tasks emoji) degradation unverified.**~~ **Closed** (#207) — verified safe; surfaced a real, separately-tracked gap (#216, server-rendered task-list checkboxes) rather than folding it into the verification task.
- ~~**Server-rendered HTML missing GFM task-list checkboxes.**~~ **Closed** (#216).
- ~~**README.md stale (`PR5` claim vs. `PR200` merged).**~~ **Closed** (#201).
- **`BACKLOG.md` role overload (backlog/changelog/decisions/security-audit/UI-audit/design-tracker in one file).** **Closed** (#208, this split).
