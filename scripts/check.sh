#!/usr/bin/env bash
# 検証の一本化。使い方: scripts/check.sh [backend|frontend|shell|secrets|e2e|infra|all]
# secrets は gitleaks で全履歴と追跡外の記事の下書きを検査する（all に含む）
set -uo pipefail
cd "$(dirname "$0")/.." || exit 2

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
  # 新しいルートを足した直後は PageProps / AppRoutes の型が無く tsc が落ちるので、先に生成する
  step "typegen" docker compose exec -T frontend npx next typegen
  step "tsc" docker compose exec -T frontend npx tsc --noEmit
}

# E2E はホストで実行する。dev 環境（開発 DB）に対して走るため all には含めない
e2e() {
  if ! { curl -sf http://localhost:3100/ >/dev/null && curl -sf http://localhost:8080/api/health >/dev/null; }; then
    echo "dev 環境に接続できない。docker compose up -d を実行する" >&2
    exit 2
  fi
  if [ ! -d e2e/node_modules ]; then
    echo "初回は cd e2e && npm ci && npx playwright install chromium" >&2
    exit 2
  fi
  step "playwright" sh -c 'cd e2e && npx playwright test'
}

# infra（CDK）はホストで実行する。認証情報なしで synth まで。all には含めない
infra() {
  if [ ! -d infra/node_modules ]; then
    echo "初回は cd infra && npm ci" >&2
    exit 2
  fi
  step "infra tsc" sh -c 'cd infra && npx tsc --noEmit'
  step "cdk synth" sh -c 'cd infra && npx cdk synth -q'
}

# ホスト実行の scripts/*.sh の静的検査。macOS 標準の bash 3.2 で構文確認し、ShellCheck と grep で落とし穴を拾う
shell_syntax() {
  local f rc=0
  for f in scripts/*.sh scripts/git-hooks/pre-commit; do
    /bin/bash -n "$f" || rc=1
  done
  return "$rc"
}

shellcheck_scripts() {
  docker run --rm -v "$PWD:/mnt:ro" -w /mnt koalaman/shellcheck:v0.11.0 -S warning scripts/*.sh scripts/git-hooks/pre-commit
}

# $VAR 直後のマルチバイト文字は bash 3.2 で変数名に取り込まれる（ShellCheck では拾えない）。${VAR} を使う
shell_multibyte() {
  if LC_ALL=C grep -nE '\$[A-Za-z_][A-Za-z0-9_]*[^ -~[:space:]]' scripts/*.sh scripts/git-hooks/pre-commit; then
    echo '$VAR 直後にマルチバイト文字がある。${VAR} に直す' >&2
    return 1
  fi
  return 0
}

shell() {
  step "bash -n" shell_syntax
  step "shellcheck" shellcheck_scripts
  step "shell multibyte" shell_multibyte
}

# ホストの gitleaks（8.30.1 で確認）を使う。コンテナ経由だと GIT_INDEX_FILE や linked worktree の .git が見えず検査をすり抜ける
# 引数: gitleaks のサブコマンドと対象。実行失敗（rc 99 以外の非 0、出力に ERR）は漏れなしと区別して失敗にする
run_gitleaks() {
  if ! command -v gitleaks >/dev/null 2>&1; then
    echo "gitleaks が無い。brew install gitleaks" >&2
    return 1
  fi
  local out rc
  out="$(GIT_CONFIG_COUNT=1 GIT_CONFIG_KEY_0=color.diff GIT_CONFIG_VALUE_0=never \
    gitleaks "$1" -v --redact --no-banner --no-color --exit-code 99 \
    --config "$PWD/.gitleaks.toml" "${@:2}" 2>&1)"
  rc=$?
  [ -n "$out" ] && printf '%s\n' "$out"
  if [ "$rc" -eq 99 ]; then
    echo "秘密情報の疑いがある" >&2
    return 1
  elif [ "$rc" -ne 0 ]; then
    echo "gitleaks の実行に失敗（rc=${rc}）" >&2
    return 1
  fi
  case "$out" in
    *' ERR '* | *'skipping file'*)
      echo "gitleaks がエラーを出した / 読めないファイルがある（検査できていない恐れ）" >&2
      return 1
      ;;
  esac
}

# docs/articles/drafts は追跡外なので git の履歴検査では見えない。dir で別に検査する
secrets() {
  step "gitleaks history" run_gitleaks git .
  if [ -n "$(find docs/articles/drafts -type f 2>/dev/null | head -n 1)" ]; then
    step "gitleaks drafts" run_gitleaks dir --follow-symlinks docs/articles/drafts
  fi
}

if [ "$target" != infra ] && ! docker compose ps --status running --services >/dev/null 2>&1; then
  echo "docker に接続できない。Docker を起動して docker compose up -d を実行する" >&2
  exit 2
fi

case "$target" in
  backend) backend ;;
  frontend) frontend ;;
  e2e) e2e ;;
  infra) infra ;;
  shell) shell ;;
  secrets) secrets ;;
  all) backend; frontend; shell; secrets ;;
  *) echo "usage: scripts/check.sh [backend|frontend|shell|secrets|e2e|infra|all]" >&2; exit 2 ;;
esac

echo
if [ ${#failed[@]} -eq 0 ]; then
  echo "check: ok ($target)"
else
  echo "check: FAILED ($target): ${failed[*]}"
  exit 1
fi
