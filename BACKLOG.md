# Jotter — Backlog

Deferred work only. Sequenced from `docs/20260727-jotter-roadmap-ai-agent.md` (product roadmap) within the constraints of `docs/jotter-initial-spec-and-build-plan.md` §14. Where the two disagree, the spec's §8 security constraints, §4 shared-hosting constraints, and §1 Markdown-on-disk invariant win, and the item sits in **Needs a decision** below until resolved.

Roadmap priorities 1–5 (search, nested folders, tags, backlinks, attachments) are **already delivered** — see spec §14.3. The roadmap's gap analysis lists them as missing because its baseline describes a different product; do not re-plan them.

This file previously also carried the shipped-item changelog, decision records, security-audit log, UI-audit log, and design-system tracker — six roles in one file, a structural cause of the reconciliation failures recorded in `docs/20260729-jotter-audit.md` (e.g. #141: this file simultaneously listing C1–C6 as resolved and as open blockers). Split per #208:

- **Shipped work** (all Milestones A–D, Spec Debt epics, v0/v1/v2 delivery, and the 2026-07-29 UI-audit follow-through) → `CHANGELOG.md`
- **Decision records** (C1–C6, the typed-property model decision, and future decisions) → `docs/decisions.md`
- **Security/correctness audit findings** → `docs/security-audit-2026.md`
- **Visual-identity design-system tracking** (#96–#110) → `docs/visual-identity.md`

As of 2026-07-29, every previously-tracked Milestone is delivered (backend and UI) and closed. Nothing is currently pending in this backlog beyond the two sections below.

---

## Needs a decision (spec §14.5)

C1, C2, C3, C5, and C6 were resolved — see `docs/decisions.md`. This section previously still listed them as open `TODO(spec)` blockers after they were resolved; that self-contradiction was found and fixed (#141).

- **Roadmap baseline provenance.** `TODO(spec): confirm whether the roadmap's gap analysis was drawn from a different product of the same name. Until confirmed, spec §14.3 governs what counts as delivered.`

## Not adopted

- **Visual canvas / whiteboard** — spec §3 N3; the roadmap itself lists whiteboard parity as a non-goal for the next cycle.
- **Chat-and-files parity, full database-view breadth** — named as non-goals by the roadmap.
- **Realtime multi-user editing** — spec §3 N1, permanently out on shared hosting.
