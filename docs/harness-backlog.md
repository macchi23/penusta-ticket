# ハーネス候補の台帳

hooks / skills / 定義修正の候補を記録する。運用ルールは `CLAUDE.md` の「ハーネスの育成」。
発生回数が 2 に達したら昇格を提案する。状態は 候補 / 提案済 / 採用 / 却下。

| 種別 | 内容 | 発生回数 | 最終発生日 | 状態 |
|---|---|---|---|---|
| hook | 編集後の自動フォーマット（pint / eslint --fix） | 0 | - | 候補 |
| hook | 停止時に `scripts/check.sh` の通過を強制 | 0 | - | 候補 |
| hook | ホスト側 php / composer の実行と、開発 DB への `migrate:fresh` をブロック | 0 | - | 候補 |
| skill | `/feature`: 調査 → 実装 → 検証 → レビューの一連（`CLAUDE.md` の「標準の流れ」を切り出す） | 1 | 2026-10-03 | 候補 |
| skill | `/audit`: code-reviewer と security-reviewer を並列実行して統合報告 | 1 | 2026-10-03 | 候補 |
| skill | `/step`: 1 ステップ分の「implementer → verifier → レビュー → 差し戻し → verifier → コミット」。購入フローで 7 回、イベントエクスポートで 3 回同じ手順をブリーフした | 2 | 2026-10-04 | 採用（`.claude/skills/step/SKILL.md`） |
| 定義修正 | implementer が、ブリーフで追加した返却項目（`model: <ID>`）を初回の返却で落とした（haiku 1 回、sonnet 1 回）。「返す形式」に「ブリーフが追加した項目も含める」を足す案 | 2 | 2026-10-03 | 採用 |
| 定義修正 | code-reviewer / security-reviewer の販売整合性チェックに「同じトランザクション内で解放してから取り直す形が仮押さえの延長に化けていないか」を足す案（B4 で両レビューが検出） | 1 | 2026-10-03 | 候補 |
| 定義修正 | code-reviewer.md の「`now` をロック取得後に取っているか」は `CheckoutService` だけが守っていて `CartService` 系と食い違う。定義を「新規・変更する経路では」と限定するか、実装を揃える | 1 | 2026-10-04 | 解消（実装を揃えた） |
| 定義修正 | Explore の返却が「答えを先頭に 1〜3 行」にならず、前置き（「調査完了しました」）+ 見出しと表になった。問いが複数のときの形を定義に書く案 | 2 | 2026-10-03 | 採用 |
| 定義修正 | implementer.md に Feature テストの定石を足す案: demo guard でユーザーを切り替えるときは `auth()->forgetGuards()`、jsonb の payload 比較は `assertEquals`（キー順が保存されない） | 1 | 2026-10-03 | 候補 |
| 定義修正 | implementer.md に「複数行の置換は BSD `sed -i` でなく Edit を使う」を足す案（macOS で失敗した。DuckDB 環境の実装、合成データ再生成の検証でも再発） | 3 | 2026-10-04 | 採用 |
| 定義修正 | E2E の定石を implementer.md / code-reviewer.md に足す案: 楽観更新のある UI では「楽観更新で出た／消えた要素」で完了を待たない（hold は対処済みで release で漏れた）。Next の route announcer も `role="alert"` なので alert は文言で絞る | 1 | 2026-10-03 | 候補 |
| 定義修正 | implementer / code-reviewer が `path` を絶対パスで返した。「リポジトリ相対」と明記する案 | 1 | 2026-10-02 | 候補 |
| check | `scripts/check.sh` に静的解析（larastan）を足す。`use Throwable` 漏れで catch が何も捕まえないバグを pint がすり抜け、レビューで検出 | 1 | 2026-10-04 | 候補 |
| 定義修正 | researcher.md に「外部ツールの挙動確認（イメージの pull、scratchpad での実行）は副作用のない検証に含める」と書く案。DuckDB の挙動が推測のまま残った | 1 | 2026-10-04 | 候補 |
| 定義修正 | implementer.md に「timestamptz との比較で Carbon をそのままバインドしない（オフセットが落ちる）」を足す案。ブリーフで注意しても初回実装でそのまま渡した。接続 TZ を UTC に固定して実害は解消済み | 1 | 2026-10-04 | 候補 |
| skill | `/dea-note`: タスク完了時に `docs/dea-notes.md` へ論点を追記する手順（見出しの形式を固定） | 0 | - | 候補 |
| 定義修正 | implementer.md に「失敗確認のための一時変更は `git stash` / コピーで行い、戻した後に `git diff` で差分を確認して返却に書く」を足す案。build.sql の一時破壊を戻し損ね（`AS` が消えた）、返却では「元へ戻した」と報告した | 1 | 2026-10-04 | 候補 |
| hook / skill | compose の全イメージが pull できるかの定期確認（`docker manifest inspect`）。タグ無しの `minio/minio` が Docker Hub から削除されていたのに、手元のキャッシュで動いて気づけなかった | 1 | 2026-10-04 | 候補 |
| 定義修正 | researcher.md に「`--rm`・ポート公開なし・ボリュームなしの使い捨てコンテナとテスト用イメージの pull は検証に使ってよい」を明記する案（MinIO 代替の検証で使用。DuckDB の件と同じ論点なので上の行とまとめてよい） | 1 | 2026-10-04 | 候補 |
| 定義修正 | 生成器を変更するブリーフに「mt_rand の呼び出し回数・順序・幅を変えない」制約と、再生成前後の指紋照合を完了条件に入れる（今回は researcher の提案で実施し、全項目一致） | 1 | 2026-10-04 | 候補 |
