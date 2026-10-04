#!/usr/bin/env bash
# 実 AWS の分析基盤（S3 / Glue / Athena）の操作。ホストの aws CLI を使う。
# 使い方: scripts/aws-analytics.sh [sync|query <sql-file>|ddl <sql-file>|funnel [table]|compare [table]|status|teardown [--yes]]
# Iceberg の学習手順: analytics/athena/iceberg/ の SQL を番号順に流す。01 / 04 / 06 / 07 / 08 は ddl、03 / 05 は query。
# 01 の後に compare events_iceberg で DuckDB と一致を確認する。ddl は実 AWS に書き込む
# sync は scripts/analytics.sh build の直後に実行する。compare は DuckDB の penusta.duckdb を読むので、
# 他の duckdb プロセス（analytics.sh shell など）と同時に動かさない
# プロファイルは PENUSTA_AWS_PROFILE（既定 personal-dev-admin）。環境の AWS_* 認証情報は実 AWS 呼び出しでは無視する
set -euo pipefail
cd "$(dirname "$0")/.."

PROFILE="${PENUSTA_AWS_PROFILE:-personal-dev-admin}"
REGION=us-east-1
STACK=PenustaAnalytics
WORKGROUP_NAME=penusta-analytics
RUSTFS_ENDPOINT=http://127.0.0.1:9000
RUSTFS_BUCKET=penusta-events
OUT_DIR=analytics/.out

usage() {
  echo "usage: scripts/aws-analytics.sh [sync|query <sql-file>|ddl <sql-file>|funnel [table]|compare [table]|status|teardown [--yes]]" >&2
  echo "iceberg: analytics/athena/iceberg/ の SQL を番号順に実行（01/04/06/07/08 は ddl、03/05 は query、01 の後に compare events_iceberg）" >&2
  exit 2
}

# 実 AWS 向け。環境変数の認証情報・エンドポイントに引きずられないよう外し、プロファイルは引数で固定する
AWS_ENV_UNSET=(-u AWS_ACCESS_KEY_ID -u AWS_SECRET_ACCESS_KEY -u AWS_SESSION_TOKEN -u AWS_PROFILE
  -u AWS_ENDPOINT_URL -u AWS_ENDPOINT_URL_S3 -u AWS_ENDPOINT_URL_STS)
# AWS_IGNORE_CONFIGURED_ENDPOINT_URLS で AWS_ENDPOINT_URL_<SERVICE> や ~/.aws/config の endpoint_url も無視させる
aws_real() { env "${AWS_ENV_UNSET[@]}" AWS_IGNORE_CONFIGURED_ENDPOINT_URLS=true aws --profile "$PROFILE" --region "$REGION" "$@"; }

# RustFS 向け。SSO の認証情報と混ざらないよう env を差し替える
aws_local() {
  env -u AWS_PROFILE -u AWS_SESSION_TOKEN -u AWS_ENDPOINT_URL \
    AWS_ACCESS_KEY_ID=penusta AWS_SECRET_ACCESS_KEY=penusta-secret AWS_DEFAULT_REGION="$REGION" \
    aws --endpoint-url "$RUSTFS_ENDPOINT" "$@"
}

require_auth() {
  local ident arn expected
  if ! ident=$(aws_real sts get-caller-identity --query '[Account,Arn]' --output text 2>/dev/null); then
    echo "AWS 認証に失敗。aws sso login --profile $PROFILE を実行する" >&2
    exit 2
  fi
  ACCOUNT=${ident%%$'\t'*}
  arn=${ident#*$'\t'}
  expected=$(env "${AWS_ENV_UNSET[@]}" AWS_IGNORE_CONFIGURED_ENDPOINT_URLS=true aws configure get sso_account_id --profile "$PROFILE" 2>/dev/null || true)
  if [[ -z "$expected" || "$ACCOUNT" != "$expected" ]]; then
    echo "アカウント不一致: 認証=${ACCOUNT}、プロファイル $PROFILE の sso_account_id=${expected:-なし}" >&2
    exit 2
  fi
  if [[ "$arn" != *assumed-role/AWSReservedSSO_* ]]; then
    echo "SSO ロールではない認証情報: $arn" >&2
    exit 2
  fi
}

require_rustfs() {
  local running
  running=$(docker compose ps --status running --services 2>/dev/null || true)
  if ! grep -qx rustfs <<<"$running"; then
    echo "rustfs が起動していない。docker compose up -d rustfs を実行する" >&2
    exit 2
  fi
}

# STACK_JSON にスタックの describe-stacks 結果を入れる。return 0=あり / 1=無し / 2=その他のエラー（メッセージを表示）
describe_stack() {
  local out rc=0
  out=$(aws_real cloudformation describe-stacks --stack-name "$STACK" --output json 2>&1) || rc=$?
  if ((rc != 0)); then
    if [[ "$out" == *"does not exist"* ]]; then
      return 1
    fi
    echo "describe-stacks が失敗: $out" >&2
    return 2
  fi
  STACK_JSON="$out"
}

stack_output() {
  jq -r --arg k "$1" '.Stacks[0].Outputs // [] | map(select(.OutputKey == $k)) | .[0].OutputValue // empty' <<<"$STACK_JSON"
}

# スタックの Outputs を読む。スタックが無ければ return 1。他のエラーや Outputs の欠落（ROLLBACK_COMPLETE 等）は exit 1
fetch_stack() {
  local rc=0
  describe_stack || rc=$?
  if ((rc == 1)); then return 1; fi
  if ((rc != 0)); then exit 1; fi
  BUCKET=$(stack_output BucketName)
  DATABASE=$(stack_output DatabaseName)
  WORKGROUP=$(stack_output WorkGroupName)
  if [[ -z "$BUCKET" || -z "$DATABASE" || -z "$WORKGROUP" ]]; then
    echo "スタック $STACK の状態が不正（$(jq -r '.Stacks[0].StackStatus' <<<"$STACK_JSON")）で Outputs が欠けている。status で確認する" >&2
    exit 1
  fi
  if [[ "$BUCKET" != "penusta-analytics-$ACCOUNT" ]]; then
    echo "バケット名 $BUCKET が penusta-analytics-$ACCOUNT と一致しない" >&2
    exit 2
  fi
}

load_stack() {
  if ! fetch_stack; then
    echo "スタック $STACK が無い。cd infra && npx cdk deploy --profile $PROFILE を実行する" >&2
    exit 2
  fi
}

# s3 ls --summarize の Total Objects を返す。$1 = s3 URI、$2 以降 = aws_local / aws_real を選ぶための関数名と追加引数
count_objects() {
  local fn="$1" uri="$2"
  "$fn" s3 ls "$uri" --recursive --summarize 2>/dev/null | awk '/Total Objects:/ {print $3}'
}

cmd_sync() {
  require_rustfs
  echo "注意: scripts/analytics.sh build の直後に実行すること（古い curated を実 S3 に載せない）"
  TMP_DIR=$(mktemp -d)
  trap 'if [[ -n "${TMP_DIR:-}" ]]; then rm -rf "$TMP_DIR"; fi' EXIT
  local tmp="$TMP_DIR" prefix
  for prefix in raw curated; do
    aws_local s3 sync "s3://$RUSTFS_BUCKET/$prefix/events/" "$tmp/$prefix/events/" --only-show-errors
    # ローカルが空のまま --delete すると実 S3 側を空にしてしまうので止める
    if [[ -z "$(find "$tmp/$prefix/events" -type f 2>/dev/null | head -n 1)" ]]; then
      echo "RustFS の $prefix/events/ が空。sync を中止する" >&2
      exit 1
    fi
  done
  for prefix in raw curated; do
    aws_real s3 sync "$tmp/$prefix/events/" "s3://$BUCKET/$prefix/events/" --delete --only-show-errors
  done
  for prefix in raw curated; do
    echo "$prefix: rustfs=$(count_objects aws_local "s3://$RUSTFS_BUCKET/$prefix/events/") s3=$(count_objects aws_real "s3://$BUCKET/$prefix/events/")"
  done
  rm -rf "$TMP_DIR"
}

# 標準出力に CSV。統計は stderr。QUERY_RESULTS_OPTIONAL=1 なら結果が取れなくても失敗にしない（DDL 用）
run_query() {
  local sql="$1" qid state reason stats errors=0 started=$SECONDS
  # if / && の条件部から呼ばれると set -e が効かないので、各コマンドの失敗を明示的に確認する
  qid=$(aws_real athena start-query-execution --work-group "$WORKGROUP" \
    --query-execution-context "Database=$DATABASE" --query-string "$sql" \
    --query QueryExecutionId --output text) || { echo "start-query-execution が失敗" >&2; exit 1; }
  [[ -n "$qid" && "$qid" != None ]] || { echo "QueryExecutionId が取れない" >&2; exit 1; }
  while true; do
    if state=$(aws_real athena get-query-execution --query-execution-id "$qid" \
      --query QueryExecution.Status.State --output text) && [[ -n "$state" ]]; then
      errors=0
    else
      errors=$((errors + 1))
      if ((errors >= 30)); then echo "get-query-execution が 30 回連続で失敗（${qid}）" >&2; exit 1; fi
      state=
    fi
    case "$state" in
      SUCCEEDED) break ;;
      FAILED | CANCELLED)
        reason=$(aws_real athena get-query-execution --query-execution-id "$qid" \
          --query QueryExecution.Status.StateChangeReason --output text) || reason="(取得失敗)"
        echo "Athena クエリが $state: $reason" >&2
        exit 1
        ;;
    esac
    if ((SECONDS - started > 600)); then
      echo "Athena クエリが 600 秒で完了しない（${qid}, state=${state:-不明}）。停止を要求する" >&2
      aws_real athena stop-query-execution --query-execution-id "$qid" >/dev/null 2>&1 || true
      exit 1
    fi
    sleep 1
  done
  stats=$(aws_real athena get-query-execution --query-execution-id "$qid" \
    --query 'QueryExecution.Statistics.[DataScannedInBytes,TotalExecutionTimeInMillis]' --output text) ||
    { echo "統計の取得に失敗" >&2; exit 1; }
  awk '{printf "scanned=%s bytes, time=%s ms\n", $1, $2}' <<<"$stats" >&2
  # CLI の自動ページングが NextToken を辿って Rows を結合する
  local results rc=0
  results=$(aws_real athena get-query-results --query-execution-id "$qid" --output json 2>&1) || rc=$?
  if ((rc != 0)); then
    if [[ "${QUERY_RESULTS_OPTIONAL:-}" == 1 ]]; then
      echo "(結果行なし)" >&2
      return 0
    fi
    echo "get-query-results が失敗: $results" >&2
    exit 1
  fi
  jq -r '.ResultSet.Rows[]? | [.Data[] | .VarCharValue // ""]
      | map(if . == "" then "" elif test("[,\"\n]") then "\"" + gsub("\""; "\"\"") + "\"" else . end)
      | join(",")' <<<"$results"
}

# query は SELECT / WITH のみ。先頭の空白行と -- 行コメントを除いた最初の語で判定する
is_read_only_sql() {
  local first
  first=$(awk '/^[[:space:]]*$/ || /^[[:space:]]*--/ {next} {match($0, /[A-Za-z]+/); print substr($0, RSTART, RLENGTH); exit}' <<<"$1" | tr '[:lower:]' '[:upper:]')
  [[ "$first" == SELECT || "$first" == WITH ]]
}

cmd_query() {
  [[ -f "${1:-}" ]] || { echo "SQL ファイルが無い: ${1:-}" >&2; exit 2; }
  local sql
  sql=$(<"$1")
  if ! is_read_only_sql "$sql"; then
    echo "query は SELECT / WITH のみ受け付ける。DDL は別手順" >&2
    exit 2
  fi
  run_query "$sql"
}

# ddl: SELECT / WITH 以外も流す（CTAS / MERGE / OPTIMIZE / VACUUM 等）。実 AWS に書き込むので SQL 全文を先に表示する。
# {{bucket}} は検証済みバケット名に置換する
cmd_ddl() {
  [[ -f "${1:-}" ]] || { echo "SQL ファイルが無い: ${1:-}" >&2; exit 2; }
  local sql
  sql=$(<"$1")
  sql=${sql//'{{bucket}}'/$BUCKET}
  {
    echo "実 AWS に書き込む（workgroup=$WORKGROUP, database=${DATABASE}）。実行する SQL:"
    echo "----"
    echo "$sql"
    echo "----"
  } >&2
  QUERY_RESULTS_OPTIONAL=1 run_query "$sql"
}

# funnel / compare で使える table と、{{source}} に埋める式
source_expr() {
  case "$1" in
    events_curated | events_iceberg) echo "$1" ;;
    events_raw)
      echo "(SELECT id, event_type, user_id, session_id, device, payload.match_id AS match_id, from_iso8601_timestamp(occurred_at) AS occurred_at FROM events_raw)"
      ;;
    *)
      echo "table は events_curated / events_raw / events_iceberg のみ: $1" >&2
      exit 2
      ;;
  esac
}

cmd_funnel() {
  local table="${1:-events_curated}" expr sql
  expr=$(source_expr "$table")
  mkdir -p "$OUT_DIR"
  sql=$(<analytics/athena/funnel_by_match.sql)
  sql=${sql//'{{source}}'/$expr}
  run_query "$sql" | tee "$OUT_DIR/athena_funnel_$table.csv"
}

# $1 = athena CSV, $2 = duckdb CSV（analytics/ からの相対パス）。不一致があれば表示して return 1
diff_csv() {
  local a="$1" b="$2" cols out total bad nontotal total_ok f
  local header="match_id,is_total,s1_view_match,s2_view_seatmap,s3_add_to_cart,s4_purchase,s4_purchase_ordered,r2,r3,r4,r_total"
  for f in "$a" "$b"; do
    if [[ "$(head -n 1 "analytics/$f" 2>/dev/null)" != "$header" ]]; then
      echo "compare: ヘッダーが想定と違う: $f" >&2
      return 1
    fi
  done
  cols="match_id:'BIGINT', is_total:'BOOLEAN', s1_view_match:'BIGINT', s2_view_seatmap:'BIGINT', s3_add_to_cart:'BIGINT',
    s4_purchase:'BIGINT', s4_purchase_ordered:'BIGINT', r2:'DOUBLE', r3:'DOUBLE', r4:'DOUBLE', r_total:'DOUBLE'"
  out=$(docker compose --profile analytics run --rm -T analytics duckdb -csv -c "
    CREATE TEMP TABLE a AS SELECT * FROM read_csv('$a', header=true, nullstr=['', 'NULL'], columns={${cols//$'\n'/ }});
    CREATE TEMP TABLE b AS SELECT * FROM read_csv('$b', header=true, nullstr=['', 'NULL'], columns={${cols//$'\n'/ }});
    WITH j AS (
      SELECT coalesce(a.is_total, b.is_total) AS is_total, coalesce(a.match_id, b.match_id) AS match_id,
        a.s1_view_match AS a_s1, b.s1_view_match AS b_s1, a.s2_view_seatmap AS a_s2, b.s2_view_seatmap AS b_s2,
        a.s3_add_to_cart AS a_s3, b.s3_add_to_cart AS b_s3, a.s4_purchase AS a_s4, b.s4_purchase AS b_s4,
        a.s4_purchase_ordered AS a_s4o, b.s4_purchase_ordered AS b_s4o,
        a.r2 AS a_r2, b.r2 AS b_r2, a.r3 AS a_r3, b.r3 AS b_r3, a.r4 AS a_r4, b.r4 AS b_r4,
        a.r_total AS a_rt, b.r_total AS b_rt,
        (a.is_total IS NULL OR b.is_total IS NULL
          OR a.s1_view_match IS DISTINCT FROM b.s1_view_match
          OR a.s2_view_seatmap IS DISTINCT FROM b.s2_view_seatmap
          OR a.s3_add_to_cart IS DISTINCT FROM b.s3_add_to_cart
          OR a.s4_purchase IS DISTINCT FROM b.s4_purchase
          OR a.s4_purchase_ordered IS DISTINCT FROM b.s4_purchase_ordered
          OR (a.r2 IS NULL) <> (b.r2 IS NULL) OR abs(a.r2 - b.r2) > 0.0005 + 1e-9
          OR (a.r3 IS NULL) <> (b.r3 IS NULL) OR abs(a.r3 - b.r3) > 0.0005 + 1e-9
          OR (a.r4 IS NULL) <> (b.r4 IS NULL) OR abs(a.r4 - b.r4) > 0.0005 + 1e-9
          OR (a.r_total IS NULL) <> (b.r_total IS NULL) OR abs(a.r_total - b.r_total) > 0.0005 + 1e-9
        ) AS mismatch
      FROM a FULL OUTER JOIN b
        ON a.is_total = b.is_total AND a.match_id IS NOT DISTINCT FROM b.match_id
    )
    SELECT * FROM j ORDER BY is_total, match_id;")
  total=$(($(wc -l <<<"$out") - 1))
  bad=$(tail -n +2 <<<"$out" | grep -c ',true$' || true)
  if ((bad > 0)); then
    echo "compare: MISMATCH ($bad / $total rows)。列は a_=athena, b_=duckdb"
    { head -n 1 <<<"$out"; tail -n +2 <<<"$out" | grep ',true$'; } || true
    return 1
  fi
  nontotal=$(tail -n +2 <<<"$out" | grep -c '^false,' || true)
  total_ok=$(tail -n +2 <<<"$out" | { grep '^true,' || true; } | awk -F, '$3 > 0 && $4 > 0' | wc -l | tr -d ' ')
  if ((nontotal < 1 || total_ok < 1)); then
    echo "compare: FAIL（空の結果。試合別 $nontotal 行、合計行 s1>0 は $total_ok 行）"
    return 1
  fi
  echo "compare: ok ($total rows)"
}

cmd_compare() {
  local table="${1:-events_curated}"
  source_expr "$table" >/dev/null
  require_rustfs
  cmd_funnel "$table" >/dev/null
  mkdir -p "$OUT_DIR"
  docker compose --profile analytics run --rm -T analytics duckdb penusta.duckdb -init setup.sql -csv \
    -c ".read queries/funnel_by_match.sql" | sed -n '/^match_id,/,$p' >"$OUT_DIR/duckdb_funnel.csv"
  diff_csv ".out/athena_funnel_$table.csv" ".out/duckdb_funnel.csv"
}

cmd_status() {
  local bucket objs tables tagged
  local rc=0
  describe_stack || rc=$?
  case "$rc" in
    0) echo "stack $STACK: present ($(jq -r '.Stacks[0].StackStatus' <<<"$STACK_JSON"))" ;;
    1) echo "stack $STACK: absent" ;;
    *) echo "stack $STACK: error（上のメッセージを参照）" ;;
  esac

  bucket=$(aws_real s3api list-buckets --query "Buckets[?starts_with(Name, 'penusta-analytics-')].Name" --output text)
  if [[ -n "$bucket" ]]; then
    objs=$(count_objects aws_real "s3://${bucket%%$'\t'*}" || true)
    echo "s3 bucket: present ($bucket, objects=${objs:-0})"
  else
    echo "s3 bucket: absent"
  fi

  local out grc=0
  out=$(aws_real glue get-database --name penusta_analytics --query Database.Name --output text 2>&1) || grc=$?
  if ((grc == 0)); then
    tables=$(aws_real glue get-tables --database-name penusta_analytics --query 'TableList[].Name' --output text)
    echo "glue database: present ($out, tables: ${tables:-none})"
  elif [[ "$out" == *EntityNotFoundException* ]]; then
    echo "glue database: absent"
  else
    echo "glue database: error ($out)"
  fi

  grc=0
  out=$(aws_real athena get-work-group --work-group "$WORKGROUP_NAME" --output text 2>&1) || grc=$?
  if ((grc == 0)); then
    echo "athena workgroup: present"
  elif [[ "$out" == *"is not found"* ]]; then
    echo "athena workgroup: absent"
  else
    echo "athena workgroup: error ($out)"
  fi

  tagged=$(aws_real resourcegroupstaggingapi get-resources --tag-filters Key=project,Values=penusta-analytics \
    --query 'length(ResourceTagMappingList)' --output text)
  if [[ "$tagged" -gt 0 ]]; then echo "tagged resources: present ($tagged)"; else echo "tagged resources: absent (0)"; fi
}

# Iceberg の DROP TABLE（バケットを空にする前に実行する）。Glue DB が無ければスキップ。サブシェルで流し、失敗は return 1 にする
teardown_iceberg() {
  local out grc=0
  out=$(aws_real glue get-database --name "$DATABASE" --query Database.Name --output text 2>&1) || grc=$?
  if ((grc != 0)); then
    if [[ "$out" == *EntityNotFoundException* ]]; then
      echo "Glue DB $DATABASE が無いので Iceberg の DROP をスキップ" >&2
      return 0
    fi
    echo "get-database が失敗: $out" >&2
    return 1
  fi
  ( QUERY_RESULTS_OPTIONAL=1 run_query "DROP TABLE IF EXISTS events_iceberg" >/dev/null )
}

teardown_s3() {
  aws_real s3 rm "s3://$BUCKET" --recursive --only-show-errors || return 1
}

teardown_stack() {
  aws_real cloudformation delete-stack --stack-name "$STACK" || return 1
  aws_real cloudformation wait stack-delete-complete --stack-name "$STACK" || return 1
}

cmd_teardown() {
  if ! fetch_stack; then
    echo "スタック $STACK は削除済み"
    cmd_status
    return 0
  fi
  if [[ "${1:-}" != "--yes" ]]; then
    local answer
    read -r -p "バケット $BUCKET を空にしスタック $STACK を削除する。バケット名を入力: " answer
    [[ "$answer" == "$BUCKET" ]] || { echo "中止" >&2; exit 1; }
  fi
  local rc=0
  if ! { teardown_iceberg && teardown_s3 && teardown_stack; }; then
    rc=1
    echo "teardown が失敗。残存を確認する" >&2
  fi
  cmd_status || true
  return "$rc"
}

cmd="${1:-}"
[[ $# -gt 0 ]] && shift
case "$cmd" in
  # 突き合わせロジックだけの検証用（AWS・認証不要）。analytics/ からの相対パスを 2 つ渡す
  diff-csv) diff_csv "$@"; exit ;;
  sync | query | ddl | funnel | compare | status | teardown) ;;
  *) usage ;;
esac

require_auth
case "$cmd" in
  status) cmd_status ;;
  teardown) cmd_teardown "$@" ;;
  sync) load_stack; cmd_sync ;;
  query) load_stack; cmd_query "$@" ;;
  ddl) load_stack; cmd_ddl "$@" ;;
  funnel) load_stack; cmd_funnel "$@" ;;
  compare) load_stack; cmd_compare "$@" ;;
esac
