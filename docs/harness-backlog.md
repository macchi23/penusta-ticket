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
| skill | `/step`: 1 ステップ分の「implementer → verifier → レビュー → 差し戻し → verifier → コミット」。購入フローで 7 回同じ手順をブリーフした | 1 | 2026-10-03 | 候補 |
| 定義修正 | implementer が、ブリーフで追加した返却項目（`model: <ID>`）を初回の返却で落とした（haiku 1 回、sonnet 1 回）。「返す形式」に「ブリーフが追加した項目も含める」を足す案 | 2 | 2026-10-03 | 採用 |
| 定義修正 | code-reviewer / security-reviewer の販売整合性チェックに「同じトランザクション内で解放してから取り直す形が仮押さえの延長に化けていないか」を足す案（B4 で両レビューが検出） | 1 | 2026-10-03 | 候補 |
| 定義修正 | code-reviewer.md の「`now` をロック取得後に取っているか」は `CheckoutService` だけが守っていて `CartService` 系と食い違う。定義を「新規・変更する経路では」と限定するか、実装を揃える | 1 | 2026-10-03 | 候補 |
| 定義修正 | Explore の返却が「答えを先頭に 1〜3 行」にならず、前置き（「調査完了しました」）+ 見出しと表になった。問いが複数のときの形を定義に書く案 | 2 | 2026-10-03 | 採用 |
| 定義修正 | implementer.md に Feature テストの定石を足す案: demo guard でユーザーを切り替えるときは `auth()->forgetGuards()`、jsonb の payload 比較は `assertEquals`（キー順が保存されない） | 1 | 2026-10-03 | 候補 |
| 定義修正 | implementer.md に「複数行の置換は BSD `sed -i` でなく Edit を使う」を足す案（macOS で失敗した） | 1 | 2026-10-03 | 候補 |
| 定義修正 | E2E の定石を implementer.md / code-reviewer.md に足す案: 楽観更新のある UI では「楽観更新で出た／消えた要素」で完了を待たない（hold は対処済みで release で漏れた）。Next の route announcer も `role="alert"` なので alert は文言で絞る | 1 | 2026-10-03 | 候補 |
| 定義修正 | implementer / code-reviewer が `path` を絶対パスで返した。「リポジトリ相対」と明記する案 | 1 | 2026-10-02 | 候補 |
