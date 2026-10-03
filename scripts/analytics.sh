#!/usr/bin/env bash
# DuckDB 分析。使い方: scripts/analytics.sh [build|funnel|shell]
# analytics/penusta.duckdb は単一プロセスしか書けない（shell を開いたまま build しない）
set -euo pipefail
cd "$(dirname "$0")/.."

running=$(docker compose ps --status running --services 2>/dev/null || true)
if ! grep -qx rustfs <<<"$running"; then
  echo "rustfs が起動していない。docker compose up -d rustfs を実行する" >&2
  exit 2
fi

duck() {
  docker compose --profile analytics run --rm -T analytics duckdb penusta.duckdb -init setup.sql "$@"
}

case "${1:-}" in
  build)
    duck -c ".read build.sql"
    # DuckDB の S3 書き込みは OVERWRITE 非対応で、OVERWRITE_OR_IGNORE は古いファイルを消さないため、
    # ローカル側の作成に成功してから curated を消して書き直す（存在しない prefix でも s3 rm は rc 0）
    docker compose --profile tools run --rm -T aws \
      s3 rm s3://penusta-events/curated/events/ --recursive >/dev/null
    duck -c ".read export_curated.sql"
    ;;
  funnel) duck -c ".read queries/funnel_by_match.sql" ;;
  shell) docker compose --profile analytics run --rm analytics duckdb penusta.duckdb -init setup.sql ;;
  *) echo "usage: scripts/analytics.sh [build|funnel|shell]" >&2; exit 2 ;;
esac
