#!/usr/bin/env bash
# Claude Code のセッションログ（JSONL）を 1 イベント 1 行で読む。読み取り専用。依存は jq のみ
# 使い方: scripts/session-log.sh -l | scripts/session-log.sh [-w 幅] [-g 正規表現] <jsonl ファイル or セッション ID>...
set -uo pipefail

usage() {
  cat <<'EOF'
使い方:
  session-log.sh -l                                  セッション一覧（開始時刻の昇順）
  session-log.sh [-w 幅] [-g 正規表現] <jsonl or ID>...  1 イベント 1 行で出す
オプション:
  -l  セッション一覧
  -w  本文の最大幅（既定 200、0 で切らない）
  -g  出力行への grep -E（大文字小文字は区別）
  -h  この説明
環境変数: SESSION_LOG_DIR でログディレクトリを上書き（既定は ~/.claude/projects/<リポジトリ root を - に置換した名前>）
EOF
}

if ! command -v jq >/dev/null 2>&1; then
  echo "jq が無い" >&2
  exit 1
fi

list=0
width=200
pattern=""
while getopts "lw:g:h" opt; do
  case "$opt" in
    l) list=1 ;;
    w) width="$OPTARG" ;;
    g) pattern="$OPTARG" ;;
    h) usage; exit 0 ;;
    *) usage >&2; exit 2 ;;
  esac
done
shift $((OPTIND - 1))

case "$width" in
  '' | *[!0-9]*) echo "-w は 0 以上の整数" >&2; usage >&2; exit 2 ;;
esac

# ログディレクトリは ID の解決と -l のときだけ求める（リポジトリ外でも jsonl のパスは読める）
dir=""
need_dir() {
  [ -n "$dir" ] && return 0
  if [ -n "${SESSION_LOG_DIR:-}" ]; then
    dir="$SESSION_LOG_DIR"
    return 0
  fi
  local root name
  if ! root="$(git rev-parse --show-toplevel 2>/dev/null)"; then
    echo "リポジトリ外でログディレクトリを決められない。SESSION_LOG_DIR を指定する" >&2
    exit 1
  fi
  name="$(printf '%s' "$root" | tr -c 'A-Za-z0-9' '-')"
  dir="$HOME/.claude/projects/$name"
}

# 実質的なユーザー発言（人の入力）を取り出す jq 定義
# shellcheck disable=SC2016
defs='
def jst: try (sub("\\.[0-9]+Z$"; "Z") | fromdateiso8601 + 32400 | strftime("%m-%d %H:%M:%S")) catch null;
def human:
  select(.type == "user" and (.isMeta != true))
  | .message.content as $c
  | (if ($c | type) == "string" then $c
     elif ($c | type) == "array" then ([$c[] | select(.type? == "text") | .text] | join("\n"))
     else "" end)
  | select(. != "" and (startswith("<local-command-") | not) and (startswith("<command-name>") | not));
'

if [ "$list" -eq 1 ]; then
  if [ "$#" -gt 0 ]; then
    usage >&2
    exit 2
  fi
  need_dir
  if [ ! -d "$dir" ]; then
    echo "ログディレクトリが無い: $dir" >&2
    exit 1
  fi
  for f in "$dir"/*.jsonl; do
    [ -e "$f" ] || continue
    id="$(basename "$f" .jsonl)"
    n=0
    for s in "$dir/$id"/subagents/*.jsonl; do
      [ -e "$s" ] && n=$((n + 1))
    done
    jq -R -n -r --arg id "$id" --arg n "$n" "$defs"'
      [ inputs | (fromjson? // empty) | select(type == "object") ] as $e
      | [ $e[] | .timestamp? | select(type == "string" and jst != null) ] as $t
      | select(($t | length) > 0)
      | ([ $e[] | human ][0] // "" | gsub("\r?\n"; " ⏎ ") | .[:80]) as $first
      | [ $t[0], ($t[0] | jst), ($t[-1] | jst), $id, $n, $first ]
      | "\(.[0])\t\(.[1]) 〜 \(.[2])  \(.[3])  subagents=\(.[4])  \(.[5])"
    ' "$f"
  done | sort | cut -f2-
  exit 0
fi

if [ "$#" -eq 0 ]; then
  usage >&2
  exit 2
fi

files=()
for a in "$@"; do
  if [ -f "$a" ]; then
    files+=("$a")
  else
    need_dir
    found=""
    if [ -f "$dir/$a.jsonl" ]; then
      found="$dir/$a.jsonl"
    else
      for c in "$dir"/*/subagents/"$a".jsonl; do
        [ -f "$c" ] && { found="$c"; break; }
      done
    fi
    if [ -z "$found" ]; then
      echo "見つからない: $a" >&2
      exit 1
    fi
    files+=("$found")
  fi
done

render() {
  jq -R -r --argjson w "$width" "$defs"'
    def oneline: gsub("\r?\n"; " ⏎ ") | if $w > 0 and length > $w then .[:$w] + "…" else . end;
    def tooltext:
      (.input // {}) as $i
      | if .name == "Bash" then ($i.command // "")
        elif (.name == "Read" or .name == "Edit" or .name == "Write") then ($i.file_path // "")
        elif (.name == "Agent" or .name == "Task") then "\($i.description // "") / \($i.subagent_type // "")"
        elif (.name == "Grep" or .name == "Glob") then ($i.pattern // "")
        else ($i | tojson) end;
    def rtext:
      if (.content | type) == "string" then .content
      elif (.content | type) == "array" then ([.content[] | select(.type? == "text") | .text] | join("\n"))
      else "" end;
    (fromjson? // empty) | select(type == "object" and (.timestamp | type) == "string")
    | (.timestamp | jst) as $ts | select($ts != null)
    | (
        (human | ["user", .]),
        (select(.type == "assistant") | .message.content | arrays | .[]
          | if .type == "text" then ["assistant", .text]
            elif .type == "tool_use" then ["tool:\(.name)", tooltext]
            else empty end),
        (select(.type == "user") | .message.content | arrays | .[] | select(.type == "tool_result")
          | [(if .is_error == true then "result!" else "result" end), rtext])
      )
    | "\($ts)  \(.[0])  \(.[1] | oneline)"
  ' "$1"
}

status=0
for f in "${files[@]}"; do
  if [ -n "$pattern" ]; then
    render "$f" | grep -E -- "$pattern"
    [ "${PIPESTATUS[1]}" -ge 2 ] && status=2
  else
    render "$f"
  fi
done
exit "$status"
