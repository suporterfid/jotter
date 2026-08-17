# Agent guide

- The authoritative build plan is `docs/jotter-initial-spec-and-build-plan.md`.
- Follow its PR sequence. Do not implement a later PR before the current PR is merged and green.
- Run PHP, Composer, Node, npm, MySQL, tests, and builds only through Docker Compose V2 via `scripts/jt.sh` or `scripts/jt.ps1`.
- Never commit `.env`, credentials, private keys, `vendor/`, `node_modules/`, `public/build/`, or `dist/`.
- Keep Markdown files on disk as the future source of truth and MySQL as a rebuildable index.
- Respect shared-hosting limits: no daemons, websockets, or shelling out from application code.
- Keep GrandpaSSOn and TaskConnect optional seams. Do not implement either neighboring system here.
- v0 (PR0–PR9) is complete and v1 is in progress. Sequence new work from `STATUS.md` §4 and `BACKLOG.md`, within spec §14 — not from the roadmap's gap analysis, whose baseline describes a different product (spec §14.1).
- Do not re-litigate a resolved entry in `docs/decisions.md`. Superseding one requires a new dated entry that references the old.
