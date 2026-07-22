#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

COMPOSE_FILES=(-f compose.yaml)
if [[ "${JOTTER_CI:-}" == "1" || "${CI:-}" == "true" || "${GITHUB_ACTIONS:-}" == "true" ]]; then
  COMPOSE_FILES+=(-f compose.ci.yaml)
fi

compose() {
  docker compose "${COMPOSE_FILES[@]}" "$@"
}

ensure_env() {
  if [[ ! -f .env ]]; then
    cp .env.example .env
  fi

  if [[ -n "$(sed -n 's/^APP_KEY=//p' .env)" \
    && -n "$(sed -n 's/^DB_PASSWORD=//p' .env)" \
    && -n "$(sed -n 's/^MYSQL_ROOT_PASSWORD=//p' .env)" ]]; then
    return
  fi

  compose build app >/dev/null

  local generated key value
  generated="$(compose run --rm --no-deps -T app php -r '
    echo "APP_KEY=base64:".base64_encode(random_bytes(32)).PHP_EOL;
    echo "DB_PASSWORD=".bin2hex(random_bytes(24)).PHP_EOL;
    echo "MYSQL_ROOT_PASSWORD=".bin2hex(random_bytes(24)).PHP_EOL;
  ')"

  while IFS='=' read -r key value; do
    [[ -n "$key" ]] || continue
    sed -i "s|^${key}=.*|${key}=${value}|" .env
  done <<< "$generated"
}

install_dependencies() {
  if ! compose run --rm --no-deps app test -f vendor/autoload.php; then
    compose run --rm --no-deps app composer install --no-interaction --prefer-dist
  fi

  if ! compose --profile dev run --rm --no-deps node test -d node_modules; then
    compose --profile dev run --rm --no-deps node npm ci
  fi
}

bootstrap() {
  ensure_env
  compose up -d --build --wait mysql
  install_dependencies
  compose --profile dev run --rm --no-deps node npm run build
  compose run --rm app php artisan migrate --force
}

usage() {
  cat <<'EOF'
Jotter Docker toolchain

Usage: ./scripts/jt.sh <verb> [args...]

Verbs:
  up        Bootstrap and start Jotter at http://localhost:8080
  down      Stop and remove containers
  test      Run Laravel and frontend unit tests
  e2e       Run the Playwright smoke test
  artisan   Run an Artisan command
  composer  Run Composer
  npm       Run npm in frontend/
  release   Build dist/jotter-release.zip and checksum
EOF
}

cmd_up() {
  bootstrap
  compose up -d --build --wait app
  echo "Jotter is available at http://localhost:${APP_PORT:-8080}"
}

cmd_test() {
  bootstrap
  compose run --rm app php artisan test "$@"
  compose --profile dev run --rm --no-deps node npm test -- "$@"
}

cmd_e2e() {
  bootstrap
  compose up -d --build --wait app
  compose --profile dev run --rm node npm run e2e -- "$@"
}

cmd_release() {
  ensure_env
  mkdir -p dist
  compose --profile tools run --rm --build release
  test -s dist/jotter-release.zip
  test -s dist/jotter-release.zip.sha256
  echo "Release written to dist/jotter-release.zip"
}

main() {
  local verb="${1:-help}"
  shift || true

  case "$verb" in
    up) cmd_up "$@" ;;
    down) ensure_env; compose down "$@" ;;
    test) cmd_test "$@" ;;
    e2e) cmd_e2e "$@" ;;
    artisan) ensure_env; compose run --rm app php artisan "$@" ;;
    composer) ensure_env; compose run --rm --no-deps app composer "$@" ;;
    npm) ensure_env; compose --profile dev run --rm --no-deps node npm "$@" ;;
    release) cmd_release ;;
    help|-h|--help) usage ;;
    *) echo "Unknown verb: $verb" >&2; usage >&2; return 1 ;;
  esac
}

main "$@"
