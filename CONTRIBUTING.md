# Contributing to Jotter

Thanks for helping. Jotter is part of the [IROH Initiative](https://github.com/suporterfid/iroh); its general contribution philosophy is in that repository's [CONTRIBUTING.md](https://github.com/suporterfid/iroh/blob/main/CONTRIBUTING.md). This file covers what is specific to this repository.

## Before you start

- Open or find an issue first. Search existing issues and the [backlog](BACKLOG.md).
- Do not re-litigate a resolved entry in [docs/decisions.md](docs/decisions.md). Superseding one needs a new dated entry that references the old.
- Respect shared-hosting limits: no daemons, websockets, or shelling out from application code.
- Report security problems privately, as described in [SECURITY.md](SECURITY.md).

## Development

Everything runs in Docker Compose V2 through `scripts/jt.sh` (or `scripts/jt.ps1` on Windows). Do not install PHP, Composer, Node, or MySQL on the host.

```sh
./scripts/jt.sh up     # bootstrap and start at http://localhost:8080
./scripts/jt.sh test   # Laravel and frontend unit tests
./scripts/jt.sh e2e    # Playwright smoke test
```

## Pull requests

1. Branch from `main` and keep the PR focused on one change.
2. There is no hosted CI. Run `./scripts/jt.sh ci` on a clean tree and paste `dist/ci/last-run.txt` into the PR. It must read `result: passed` for the PR's head commit.
3. `./scripts/jt.sh ci:install-hook` enables a pre-push hook that enforces this.
4. Update the docs when you change configuration, commands, or behavior.
5. Never commit `.env`, credentials, private keys, `vendor/`, `node_modules/`, `public/build/`, or `dist/`.

Commit and PR titles are short and imperative, for example `Show only the sign-in screen before authentication`.
