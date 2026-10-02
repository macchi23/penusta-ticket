#!/usr/bin/env bash
# 検証の一本化。使い方: scripts/check.sh [backend|frontend|all]
set -uo pipefail
cd "$(dirname "$0")/.."

target="${1:-all}"
failed=()

step() {
  local name="$1"
  shift
  echo "==> $name"
  if "$@"; then
    echo "    ok: $name"
  else
    echo "    FAILED: $name"
    failed+=("$name")
  fi
}

# テストは開発 DB (penusta) と別の penusta_test で走らせる
ensure_test_db() {
  docker compose exec -T db psql -U penusta -d postgres -tAc \
    "SELECT 1 FROM pg_database WHERE datname = 'penusta_test'" | grep -q 1 \
    || docker compose exec -T db createdb -U penusta penusta_test
}

phpunit() {
  # config がキャッシュされていると phpunit.xml の DB 指定が効かない
  local ansi=--no-ansi
  [ -t 1 ] && ansi=--ansi
  docker compose exec -T app php artisan config:clear >/dev/null \
    && docker compose exec -T app php artisan test "$ansi"
}

backend() {
  step "pint" docker compose exec -T app ./vendor/bin/pint --test
  step "test db" ensure_test_db
  step "phpunit" phpunit
}

frontend() {
  step "eslint" docker compose exec -T frontend npm run lint
  step "tsc" docker compose exec -T frontend npx tsc --noEmit
}

if ! docker compose ps --status running --services >/dev/null 2>&1; then
  echo "docker に接続できない。Docker を起動して docker compose up -d を実行する" >&2
  exit 2
fi

case "$target" in
  backend) backend ;;
  frontend) frontend ;;
  all) backend; frontend ;;
  *) echo "usage: scripts/check.sh [backend|frontend|all]" >&2; exit 2 ;;
esac

echo
if [ ${#failed[@]} -eq 0 ]; then
  echo "check: ok ($target)"
else
  echo "check: FAILED ($target): ${failed[*]}"
  exit 1
fi
