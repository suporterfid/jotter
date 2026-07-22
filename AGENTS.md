# Agent guide

- The authoritative build plan is `docs/jotter-initial-spec-and-build-plan.md`.
- Follow its PR sequence. Do not implement a later PR before the current PR is merged and green.
- Run PHP, Composer, Node, npm, MySQL, tests, and builds only through Docker Compose V2 via `scripts/jt.sh` or `scripts/jt.ps1`.
- Never commit `.env`, credentials, private keys, `vendor/`, `node_modules/`, `public/build/`, or `dist/`.
- Keep Markdown files on disk as the future source of truth and MySQL as a rebuildable index.
- Respect shared-hosting limits: no daemons, websockets, or shelling out from application code.
- Keep GrandpaSSOn and TaskConnect optional seams. Do not implement either neighboring system here.
- Do not begin v1 work.
