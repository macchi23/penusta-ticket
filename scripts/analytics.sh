#!/usr/bin/env bash
# DuckDB 分析。使い方: scripts/analytics.sh [build|funnel|query <名前>|shell]
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

# build.sql が読む業務テーブル（raw/<表>/part-0000.jsonl.gz）。build.sql の read_json('s3://penusta-events/raw/<t>/...) と揃える。
# events は dt= 分割の別経路なので、ここには入れず check_raw で別に見る
RAW_TABLES=(seasons teams matches league_standings league_matches stands seat_categories blocks match_block_allocations ticket_types match_prices sale_phases orders order_items tickets)

# DuckDB は対象 prefix にファイルが 1 つも無いと No files found で止まる。build の前に raw の有無を s3 ls 1 回で確かめる
check_raw() {
  local listing missing=() t rc=0
  # s3 ls は 1 件も無いと出力なしで rc 1 を返す。空の一覧として扱い、それ以外の失敗（バケットが無い等）だけ止める
  listing=$(docker compose --profile tools run --rm -T aws s3 ls s3://penusta-events/raw/ --recursive) || rc=$?
  if [ "$rc" -ne 0 ] && [ "$rc" -ne 1 ]; then
    echo "raw の一覧を取れなかった（rc=${rc}）" >&2
    exit "$rc"
  fi
  for t in "${RAW_TABLES[@]}"; do
    grep -qE "[[:space:]]raw/${t}/part-0000\.jsonl\.gz\$" <<<"$listing" || missing+=("$t")
  done
  grep -qE "[[:space:]]raw/events/.*\.jsonl\.gz\$" <<<"$listing" || missing+=("events")
  if [ "${#missing[@]}" -gt 0 ]; then
    echo "raw に出力の無い表がある: ${missing[*]}" >&2
    echo "先に docker compose exec app php artisan tables:export（events は events:export）を流す" >&2
    exit 2
  fi
}

# curated/events は 1 dt 1 ファイル（part-0.parquet）の前提。part-1 以降があれば、その dt を並べて止める
check_curated_single_file() {
  local listing extra
  # 0 件（rc 1）も失敗として扱う。export_curated.sql の後に curated/events が空なのは異常
  listing=$(docker compose --profile tools run --rm -T aws s3 ls s3://penusta-events/curated/events/ --recursive) || {
    echo "curated/events の一覧を取れなかった（空か、取得に失敗）" >&2
    exit 1
  }
  # dt=*/part-0.parquet 以外の行はすべて異常。パス（4 列目）ごと出す
  extra=$(grep -vE '[[:space:]]curated/events/dt=[0-9-]+/part-0\.parquet$' <<<"$listing" | awk '{print $4}' || true)
  if [ -n "$extra" ]; then
    echo "curated/events に dt=*/part-0.parquet 以外のファイルがある:" >&2
    echo "$extra" >&2
    exit 1
  fi
}

case "${1:-}" in
  build)
    check_raw
    duck -c ".read build.sql"
    # DuckDB の S3 書き込みは OVERWRITE 非対応で、OVERWRITE_OR_IGNORE は古いファイルを消さないため、
    # ローカル側の作成に成功してから curated を消して書き直す（存在しない prefix でも s3 rm は rc 0）
    docker compose --profile tools run --rm -T aws \
      s3 rm s3://penusta-events/curated/ --recursive >/dev/null
    duck -c ".read export_curated.sql"
    check_curated_single_file
    ;;
  funnel) duck -c ".read queries/funnel_by_match.sql" ;;
  query)
    name="${2:-}"
    if [[ "$name" == *_pg ]]; then
      echo "analytics/queries/${name}.sql は psql 用のファイル。PostgreSQL で流す（実行方法は同ファイル冒頭）" >&2
      exit 2
    fi
    if [[ ! "$name" =~ ^[a-z0-9_]+$ ]] || [ ! -f "analytics/queries/${name}.sql" ]; then
      echo "analytics/queries/<名前>.sql が無い（名前は [a-z0-9_]+）。あるもの:" >&2
      ls analytics/queries >&2
      exit 2
    fi
    duck -c ".read queries/${name}.sql"
    ;;
  shell) docker compose --profile analytics run --rm analytics duckdb penusta.duckdb -init setup.sql ;;
  *) echo "usage: scripts/analytics.sh [build|funnel|query <名前>|shell]" >&2; exit 2 ;;
esac
