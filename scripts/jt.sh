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

  compose --profile dev run --rm --no-deps node npm ci
}

bootstrap() {
  ensure_env
  compose up -d --build --wait mysql
  install_dependencies
  compose --profile dev run --rm --no-deps node npm run build
  compose run --rm app php artisan migrate --force --seed
}

prepare_test_database() {
  compose exec -T mysql sh -c \
    'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -e "CREATE DATABASE IF NOT EXISTS jotter_testing; GRANT ALL PRIVILEGES ON jotter_testing.* TO '\''${JOTTER_DB_USERNAME}'\''@'\''%'\'';"'
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
  release         Build dist/jotter-release-<version>.zip and checksum
  release:verify  Scan a release ZIP for secrets and private keys (newest, or path arg)
  release:doctor  Extract a release ZIP into dist/install-test and run jotter:doctor there
  ci              Run the full CI pipeline locally (up, test, e2e, release, release:verify)
                  Options: --allow-dirty, --dry-run. Summary: dist/ci/last-run.txt
  ci:install-hook Enable the pre-push hook that requires a passing `ci` for pushed commits
EOF
}

cmd_up() {
  bootstrap
  compose up -d --build --wait app
  echo "Jotter is available at http://localhost:${APP_PORT:-8080}"
}

cmd_test() {
  bootstrap
  prepare_test_database
  compose run --rm -e DB_DATABASE=jotter_testing app php artisan test "$@"
  # Arguments target Laravel's test runner. Frontend tests use their own
  # Vitest invocation through the `npm` verb and must not receive PHPUnit-only
  # flags such as `--filter`.
  compose --profile dev run --rm --no-deps node npm test
}

cmd_e2e() {
  bootstrap
  # The OAuth consent spec (frontend/e2e/oauth-consent.spec.ts) needs the
  # embedded OAuth server; it is off by default everywhere else.
  export JOTTER_OAUTH_ENABLED=true
  compose run --rm app php artisan migrate:fresh --seed --force
  compose up -d --build --wait app
  compose run --rm app php artisan platform:bootstrap-admin admin@example.com password12345 || true
  compose --profile dev run --rm node npm run e2e -- "$@"
}

# Git tag when HEAD is exactly tagged, otherwise 0.0.0-<short sha>. Sanitized so
# it is safe inside a file name.
release_version() {
  local version
  version="$(git describe --tags --exact-match 2>/dev/null || true)"
  if [[ -z "$version" ]]; then
    version="0.0.0-$(git rev-parse --short HEAD)"
  fi
  printf '%s' "$version" | tr -c 'A-Za-z0-9._-' '-'
}

# Newest release ZIP in dist/, or the explicit path given as first argument.
latest_release_zip() {
  local explicit="${1:-}"
  if [[ -n "$explicit" ]]; then
    printf '%s' "$explicit"
    return
  fi
  ls -t dist/jotter-release-*.zip 2>/dev/null | head -n 1 || true
}

cmd_release() {
  ensure_env
  mkdir -p dist
  export RELEASE_VERSION="$(release_version)"
  local zip_name="jotter-release-${RELEASE_VERSION}.zip"
  rm -f "dist/${zip_name}" "dist/${zip_name}.sha256"
  compose --profile tools run --rm --build release
  test -s "dist/${zip_name}"
  test -s "dist/${zip_name}.sha256"
  (cd dist && sha256sum -c "${zip_name}.sha256")
  echo "Release written to dist/${zip_name} (version: ${RELEASE_VERSION}, commit: $(git rev-parse --short HEAD))"
}

cmd_release_verify() {
  local zip_path
  zip_path="$(latest_release_zip "${1:-}")"

  if [[ -z "$zip_path" || ! -s "$zip_path" ]]; then
    echo "Release zip is missing or empty: ${zip_path:-dist/jotter-release-<version>.zip}. Run ./scripts/jt.sh release first." >&2
    return 1
  fi

  case "$zip_path" in
    dist/*) ;;
    *) echo "Release zip must live under dist/ so the container can read it: $zip_path" >&2; return 1 ;;
  esac

  ensure_env
  compose run --rm \
    -e "JOTTER_RELEASE_ZIP=/var/www/html/${zip_path}" \
    app php artisan test --filter=ReleaseZipSecurityTest
  echo "Release ZIP security verification passed: ${zip_path}"
}

# Installs the ZIP the way a shared host would (fresh directory, its own .env,
# its own vault outside public/) and runs the doctor inside that copy. Reuses the
# dev MySQL database (schema state is only read); `schedule:run` is executed once
# so the heartbeat check has something to report. Usage:
#   ./scripts/jt.sh release:doctor [dist/jotter-release-<version>.zip] [--json]
cmd_release_doctor() {
  local zip_arg=''
  if [[ "${1:-}" == *.zip ]]; then
    zip_arg="$1"
    shift
  fi

  local zip_path
  zip_path="$(latest_release_zip "$zip_arg")"

  if [[ -z "$zip_path" || ! -s "$zip_path" ]]; then
    echo "Release zip is missing or empty. Run ./scripts/jt.sh release first." >&2
    return 1
  fi

  ensure_env
  local install_dir='dist/install-test'

  # The dev compose service injects the repository .env into the container, and
  # real environment variables win over a .env file. Override the values that
  # must differ for the installation under test.
  local -a overrides=(
    APP_ENV=production
    APP_DEBUG=false
    APP_URL=https://install-test.example.invalid
    APP_INSTANCE_SLUG=install-test
    LOG_CHANNEL=single
    CACHE_STORE=database
    SESSION_DRIVER=database
    QUEUE_CONNECTION=sync
    MAIL_MAILER=log
    "VAULT_BASE_PATH=/var/www/html/${install_dir}/vault"
  )

  # Staged on the host (dist/ is host-owned); copied into the extracted app by
  # the container, which owns everything under install_dir.
  {
    grep -E '^(APP_KEY|DB_DATABASE|DB_USERNAME|DB_PASSWORD)=' .env
    printf 'DB_CONNECTION=mysql\nDB_HOST=mysql\nDB_PORT=3306\n'
    printf '%s\n' "${overrides[@]}"
  } > "${install_dir}.env"

  local -a env_flags=()
  local override
  for override in "${overrides[@]}"; do
    env_flags+=(-e "$override")
  done

  # Extraction, .env placement, and cleanup all happen inside the container so
  # file ownership never blocks a rerun from the host.
  compose run --rm "${env_flags[@]}" \
    -e "JOTTER_INSTALL_DIR=/var/www/html/${install_dir}" \
    -e "JOTTER_INSTALL_ZIP=/var/www/html/${zip_path}" \
    app sh -c '
      set -e
      rm -rf "$JOTTER_INSTALL_DIR"
      mkdir -p "$JOTTER_INSTALL_DIR/vault"
      unzip -q "$JOTTER_INSTALL_ZIP" -d "$JOTTER_INSTALL_DIR"
      cp "$JOTTER_INSTALL_DIR.env" "$JOTTER_INSTALL_DIR/app/.env"
      cd "$JOTTER_INSTALL_DIR/app"
      php artisan schedule:run --no-ansi >/dev/null
      php artisan jotter:doctor "$@"
    ' sh "$@"
}

# --- Local CI -----------------------------------------------------------------
#
# Jotter has no hosted CI. `ci` runs the full pipeline that used to live in
# .github/workflows/ci.yml: up, test, e2e, release, release:verify, then tears
# everything down. It runs in its own Compose project (jotter-ci-<hash>) with the
# CI overrides, so it never touches the development database and publishes no
# host ports. A summary goes to dist/ci/last-run.txt, the full log to
# dist/ci/last-run.log, and a passing run on a clean tree writes
# dist/ci/passed-<sha>, which the pre-push hook (.githooks/pre-push) accepts.

# `composer install` runs first, always: vendor/ is bind-mounted and shared with
# the dev stack, and `up` skips the install when vendor/autoload.php exists, so
# a broken composer.lock would otherwise go unnoticed.
CI_STEPS=("composer install --no-interaction --prefer-dist" up test e2e release release:verify)

ci_project_name() {
  printf 'jotter-ci-%s' "$(printf '%s' "$ROOT_DIR" | sha256sum | cut -c1-12)"
}

ci_child() {
  JOTTER_CI=1 CI=true COMPOSE_PROJECT_NAME="$(ci_project_name)" "$ROOT_DIR/scripts/jt.sh" "$@"
}

ci_dump_diagnostics() {
  echo "--- storage/logs/laravel.log (tail) ---"
  tail -n 200 storage/logs/laravel.log 2>/dev/null || echo "(no laravel.log)"
  echo "--- docker compose logs app (tail) ---"
  JOTTER_CI=1 COMPOSE_PROJECT_NAME="$(ci_project_name)" \
    docker compose -f compose.yaml -f compose.ci.yaml logs --tail=200 app 2>&1 || true
}

cmd_ci() {
  local allow_dirty=0 dry_run=0 arg
  for arg in "$@"; do
    case "$arg" in
      --allow-dirty) allow_dirty=1 ;;
      --dry-run) dry_run=1 ;;
      *) echo "Unknown ci option: $arg (use --allow-dirty or --dry-run)" >&2; return 1 ;;
    esac
  done

  local sha dirty=0
  sha="$(git rev-parse HEAD)"
  if [[ -n "$(git status --porcelain)" ]]; then
    dirty=1
    if [[ "$allow_dirty" -eq 0 ]]; then
      echo "jt ci: the working tree has uncommitted changes, so the result would not describe ${sha:0:12}." >&2
      echo "Commit or stash them, or rerun with --allow-dirty (no pass marker is written then)." >&2
      return 1
    fi
  fi

  if [[ "$dry_run" -eq 1 ]]; then
    echo "jt ci (dry run): commit ${sha:0:12}, project $(ci_project_name), dirty=${dirty}"
    printf 'step: %s\n' "${CI_STEPS[@]}"
    echo "then: down --volumes --remove-orphans"
    return 0
  fi

  local out_dir='dist/ci'
  mkdir -p "$out_dir"
  local log="${out_dir}/last-run.log" summary="${out_dir}/last-run.txt"
  rm -f "${out_dir}/passed-${sha}"
  : > "$log"

  local started result='passed' failed_step='' step step_start status
  started="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  local -a lines=()

  # shellcheck disable=SC2064
  trap "ci_child down --volumes --remove-orphans >>'$log' 2>&1 || true" EXIT

  for step in "${CI_STEPS[@]}"; do
    echo "==> jt ci: ${step}" | tee -a "$log"
    step_start=$SECONDS
    set +e
    # shellcheck disable=SC2086 # word-split the step into verb + arguments
    ci_child $step 2>&1 | tee -a "$log"
    status=${PIPESTATUS[0]}
    set -e
    lines+=("$(printf '%-15s %-6s %4ss' "${step%% *}" "$([[ $status -eq 0 ]] && echo ok || echo FAIL)" "$((SECONDS - step_start))")")
    if [[ $status -ne 0 ]]; then
      result='failed'
      failed_step="${step%% *}"
      ci_dump_diagnostics 2>&1 | tee -a "$log"
      break
    fi
  done

  {
    echo "result:   ${result}${failed_step:+ (step: ${failed_step})}"
    echo "commit:   ${sha}"
    echo "branch:   $(git rev-parse --abbrev-ref HEAD)"
    echo "dirty:    $([[ $dirty -eq 1 ]] && echo yes || echo no)"
    echo "started:  ${started}"
    echo "finished: $(date -u +%Y-%m-%dT%H:%M:%SZ)"
    echo "project:  $(ci_project_name)"
    echo
    printf '%s\n' "${lines[@]}"
  } > "$summary"

  echo
  cat "$summary"

  if [[ "$result" != 'passed' ]]; then
    echo "jt ci FAILED at ${failed_step}. Full log: ${log}" >&2
    return 1
  fi

  if [[ "$dirty" -eq 0 ]]; then
    cp "$summary" "${out_dir}/passed-${sha}"
  fi
  echo "jt ci passed. Paste ${summary} into the PR."
}

cmd_ci_install_hook() {
  git config core.hooksPath .githooks
  echo "pre-push hook enabled (core.hooksPath=.githooks). Skip once with JOTTER_SKIP_CI=1 git push."
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
    release:verify) cmd_release_verify "$@" ;;
    release:doctor) cmd_release_doctor "$@" ;;
    ci) cmd_ci "$@" ;;
    ci:install-hook) cmd_ci_install_hook ;;
    help|-h|--help) usage ;;
    *) echo "Unknown verb: $verb" >&2; usage >&2; return 1 ;;
  esac
}

main "$@"
