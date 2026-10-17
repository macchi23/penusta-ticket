# ハーネス候補の台帳

hooks / skills / 定義修正の候補を記録する。運用ルールは `CLAUDE.md` の「ハーネスの育成」。
発生回数が 2 に達したら昇格を提案する。状態は 候補 / 提案済 / 採用 / 却下。

| 種別 | 内容 | 発生回数 | 最終発生日 | 状態 |
|---|---|---|---|---|
| hook | 編集後の自動フォーマット（pint / eslint --fix） | 0 | - | 候補 |
| hook | 停止時に `scripts/check.sh` の通過を強制 | 0 | - | 候補 |
| hook | ホスト側 php / composer の実行と、開発 DB への `migrate:fresh` をブロック。2026-10-12: リセール Step 4 の implementer が `DB_DATABASE=penusta_test` を付け忘れて `migrate:fresh --seed` を流し、開発 DB を作り直した（ユーザー承認のうえ生成器で再生成） | 1 | 2026-10-12 | 候補 |
| skill | `/feature`: 調査 → 実装 → 検証 → レビューの一連（`CLAUDE.md` の「標準の流れ」を切り出す） | 1 | 2026-10-03 | 候補 |
| skill | `/audit`: code-reviewer と security-reviewer を並列実行して統合報告 | 1 | 2026-10-03 | 候補 |
| skill | `/step`: 1 ステップ分の「implementer → verifier → レビュー → 差し戻し → verifier → コミット」。購入フローで 7 回、イベントエクスポートで 3 回同じ手順をブリーフした | 2 | 2026-10-04 | 採用（`.claude/skills/step/SKILL.md`） |
| 定義修正 | implementer が、ブリーフで追加した返却項目（`model: <ID>`）を初回の返却で落とした（haiku 1 回、sonnet 1 回）。「返す形式」に「ブリーフが追加した項目も含める」を足す案 | 2 | 2026-10-03 | 採用 |
| 定義修正 | code-reviewer / security-reviewer の販売整合性チェックに「同じトランザクション内で解放してから取り直す形が仮押さえの延長に化けていないか」を足す案（B4 で両レビューが検出） | 1 | 2026-10-03 | 候補 |
| 定義修正 | code-reviewer.md の「`now` をロック取得後に取っているか」は `CheckoutService` だけが守っていて `CartService` 系と食い違う。定義を「新規・変更する経路では」と限定するか、実装を揃える | 1 | 2026-10-04 | 解消（実装を揃えた） |
| 定義修正 | Explore の返却が「答えを先頭に 1〜3 行」にならず、前置き（「調査完了しました」）+ 見出しと表になった。問いが複数のときの形を定義に書く案 | 2 | 2026-10-03 | 採用 |
| 定義修正 | implementer.md に Feature テストの定石を足す案: demo guard でユーザーを切り替えるときは `auth()->forgetGuards()`、jsonb の payload 比較は `assertEquals`（キー順が保存されない）。2026-10-11: AdminAllocationApiTest の公開 seatmap の検証が、直前のリクエストの admin をキャッシュしたまま通っていた（レビューで検出） | 2 | 2026-10-11 | 採用（`.claude/agents/implementer.md`） |
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
| 定義修正 | researcher.md に「plan mode 中でも plan ファイルは書かない（メインが書く）」を明記する案。plan mode の指示と「レポート用ファイルを書かない」定義が衝突した（scheduler 調査） | 1 | 2026-10-04 | 候補 |
| 定義修正 | implementer（haiku）がブリーフの「ログ・出力はそのまま貼る」を守らず要約だけ返した（scheduler の compose 追加）。返却形式に「貼れと指示された出力は省略しない」を足す案 | 1 | 2026-10-04 | 候補 |
| 定義修正 | compose にサービスを足すブリーフ / レビュー観点に「同じ Dockerfile を使うなら `image:` を共有する」を入れる案。別名だとビルド時期の違いで PHP 8.4.24 / 8.4.26 にずれた（レビューで検出） | 1 | 2026-10-04 | 候補 |
| check | ホスト実行の `scripts/*.sh` を check の対象にする（ShellCheck をコンテナで実行 + `/bin/bash -n`）。macOS の bash 3.2 の落とし穴をレビューで 2 ステップ続けて検出した（`local` 変数を EXIT trap で参照して unbound、条件部から呼んだ関数で `set -e` が無効、`$VAR` 直後のマルチバイト文字） | 2 | 2026-10-05 | 採用（`scripts/check.sh shell`、`all` に含む） |
| 定義修正 | researcher のブリーフに「環境の前提（ホストのツール有無・バージョン、~/.aws のプロファイル）は自分で確かめる」を入れる案。「ホストの Node 有無は不明」と書いたが読み取りで確認できた | 1 | 2026-10-05 | 候補 |
| 定義修正 | 読み取り専用のレビュー役に、ファイル作成や docker 実行が要る動作確認を頼んだ（diff-csv の 5 ケース）。動作確認は implementer / verifier のブリーフに入れる | 1 | 2026-10-05 | 候補 |
| 定義修正 | implementer に Web 取得手段が無く、ブリーフの「公式ドキュメントで確認」を満たせなかった（Iceberg の timestamp 精度を記憶で書き、レビューで重大として検出）。外部仕様の確認は researcher に分けるか、implementer に WebFetch を許可する | 1 | 2026-10-05 | 候補 |
| 定義修正 | security-reviewer の観点に「`.claude/settings*.json` の allow 追加」を入れる案。`query:*` の自動許可で任意 SQL（DROP / UNLOAD）を確認なしに流せる穴をレビューが検出した | 1 | 2026-10-05 | 候補 |
| 定義修正 | implementer.md に同時実行テストの定石を足す案: `tests/Concerns/UsesProbeConnection`（別接続で行を握り lock_timeout で待ちを判定）、`beforeExecuting` で「ロック直前のコミット」を再現、`DatabaseTruncation` は終了時に自分で truncate、最上位トランザクション前提のサービスは HTTP テストも `DatabaseTruncation`。割当エディタのステップ 3・4・5 の implementer がそれぞれ「ブリーフに書いてあれば探索が減る」と返した | 2 | 2026-10-06 | 採用（`.claude/agents/implementer.md`） |
| check | ロック順の宣言（orders → allocation → tickets）を SQL ログから検査するテストヘルパの共通化。`setUnreservedQuantities` の tickets UPDATE → allocation の順序違反がテストをすり抜け、security-reviewer が検出 | 1 | 2026-10-06 | 候補 |
| 定義修正 | レビューの修正案を差し戻しにそのまま流したら、別の不変条件を壊した（ロック後に `now` を取り直す → カート内の held の期限が揃わなくなる。再レビューで検出して撤回）。販売整合性まわりの修正案は、副作用をメインが確かめてから渡す | 1 | 2026-10-06 | 候補 |
| 定義修正 | implementer がブリーフで必読とした Next.js の docs を一部読まずに実装した（authInterrupts.md、server-actions.md。差し戻しで再指示しても server-actions.md は未読のまま）。返却の「読んだ docs」に未読分と理由を書かせるだけでなく、必読は読んでから書くことを定義に入れる | 1 | 2026-10-06 | 候補 |
| 定義修正 | E2E を回すブリーフに「dev DB が migrate 済みか」を前提として書く。未 migrate のまま E2E を流し、`/api/demo/users` の 500 で既存 3 spec が落ちた | 1 | 2026-10-06 | 候補 |
| 定義修正 | `ApiErrorCode` を増やしても `server-api.ts` の許可リストが追随せず、新コードが unknown に潰れた（レビューで検出）。許可リストを `Record<ApiErrorCode, true>` にして tsc で検出できるようにした | 1 | 2026-10-06 | 解消（型で検出） |
| 定義修正 | ルートの `{match}` / `{block}` に数値制約が無く、非数値 id が 22P02 の 500 になった（公開ルートと管理ルートで同じ漏れ）。`Route::pattern` でグローバルに付けた | 1 | 2026-10-06 | 解消（`Route::pattern`） |
| 定義修正 | 原因究明のブリーフに「最初にログで実際のリクエストパス・ステータスを確かめる」を入れる案。/admin の 404 報告を、仮説がすべて /admin 前提のまま渡した（実際は `/matches/admin` の打ち間違い） | 1 | 2026-10-06 | 候補 |
| 定義修正 | E2E の定石（implementer.md）に「実 UI で Server Action を呼んだ後に `goto` するときは、画面の反映（切替後のユーザー名など）を待つ」を足す案。初回 (c) が古い cookie で遷移して失敗、A→B の切替で待ちが空振りする形もレビューで検出 | 1 | 2026-10-06 | 候補 |
| check | `scripts/check.sh` に frontend の本番ビルド（`next build`）を足す。dev サーバーの `.next` と衝突しない出力先で。割当エディタでルートの `not-found.tsx`（ビルド時に事前生成）と `experimental.authInterrupts` を足したが、ビルドは一度も通していない。ユーザー了承済み、次回以降に実装 | 1 | 2026-10-06 | 候補 |
| 環境 | Bash の cwd がコマンドごとに戻るため、`cd` 付きの複合コマンドで相対パスの heredoc の書き込み先がずれた（implementer） | 1 | 2026-10-06 | 候補 |
| 定義修正 | 並列の implementer が作った untracked ファイルで、もう一方の `check.sh backend` の pint が落ちた。ブリーフか implementer.md に「完了前に自分の変更ファイルへ pint をかける」を入れる案（P1 / P2a の並行） | 1 | 2026-10-08 | 候補 |
| 定義修正 | ブリーフの仕様が両立しにくかった（平日節は「水曜」と「祝日優先」）。日程など組合せの仕様は、優先順位と数え方をブリーフで決めておく | 1 | 2026-10-08 | 候補 |
| 定義修正 | レビュー指摘の修正で挙動が変わった（フォーカス枠を `group-focus-visible` から onFocus に変え、マウスでも枠が出るようになった。メインが差分確認で検出）。修正ブリーフに「元の挙動の条件（例: キーボード時のみ）を保つ」を明記する | 1 | 2026-10-08 | 候補 |
| 定義修正 | implementer が長時間コマンドの待ちで 600 秒無応答になり停止した（P3a）。implementer.md に「10 分近いコマンドは run_in_background + until ループで待つ、先に小さい規模で所要時間を見積もる」を足す案 | 1 | 2026-10-10 | 候補 |
| 定義修正 | 「全テーブル一致」の決定性テストが列を選んで指紋を取り、bcrypt の salt と order_items の差を見逃した（レビューで検出）。決定性テストは全列比較、除外は理由付きで明示する | 1 | 2026-10-10 | 候補 |
| hook / check | `app/Models` `app/Services` から `App\Support\DemoData` を use していないかの grep。販売フェーズのテンプレートが生成器の定数に依存した（レビューで検出、`MembershipKind` Enum に寄せて解消） | 1 | 2026-10-11 | 候補 |
| 定義修正 | researcher のブリーフに、販売モデルの前提（年パス・会員をいつ売るか、途中加入・解約の有無）を最初から書く。調査の途中で前提を追加した（販売フェーズの年パス検討） | 1 | 2026-10-11 | 候補 |
| 定義修正 | dev-notes.md に「開発 DB の psql ユーザーは `backend/.env` の `DB_USERNAME`（postgres ではない）」を書く（researcher の指摘） | 1 | 2026-10-11 | 候補 |
| 定義修正 | implementer.md の Feature テストの定石に足す案: `orders_one_pending_per_user_match` を踏むので pending を複数作るときは user / match を分ける、Enum キャストのモデルで CHECK を検証するときは `DB::table()->insert` を使う | 1 | 2026-10-11 | 候補 |
| 定義修正 | verifier.md / implementer.md に「`scripts/check.sh` は 120 秒近くかかるので、Bash の timeout を 600000 にしてフォアグラウンドで実行する」を足す案。バックグラウンドに回した verifier が報告を返さずに終了した（販売フェーズのステップ 2）。2026-10-12: phpunit が 80〜100 秒になり、リセール Step 2 の implementer も同じ指摘 | 2 | 2026-10-12 | 採用（`.claude/agents/verifier.md` / `implementer.md`） |
| 定義修正 | implementer.md の Feature テストの定石に「`BuildsSaleFixture` のブロックコード（M1 等）は `StadiumSeeder` と衝突する。DatabaseSeeder を使うテストは fixture を使うクラスと分ける」を足す案 | 1 | 2026-10-11 | 候補 |
| hook / check | `PurchaseException` の code（`new self('xxx'`）と `frontend/src/lib/api.ts` の `ApiErrorCode` を突き合わせる。販売フェーズで `not_eligible` を backend に足した時点で api.ts が未更新のまま、初回レビューも見落とした（再レビューで検出。今回はステップ分割で次ステップの予定だった） | 1 | 2026-10-11 | 候補 |
| 定義修正 | 購入まわりのブリーフに「CartTest / CheckoutTest は `BuildsSaleFixture` を使わず自前で試合を作るので、前提データ（フェーズ等）を足したら両方にも足す」を書く。足さずに 44 件落ちた（implementer が自力で対処） | 1 | 2026-10-11 | 候補 |
| 定義修正 | E2E の定石（implementer.md）に「試合の選び方は販売フェーズの現在状態（会員限定か、ends_at までの余裕）を前提にする」を足す案。日付の経過で未販売の試合が FC 先行に入り、非会員で購入する spec が落ちた | 1 | 2026-10-11 | 候補 |
| 定義修正 | 「DB の値を読む」仕様のテスト観点をブリーフに入れる: 既定値（テンプレート）と同じデータでしか走らないと、定数のままの実装と区別できない。値をずらしたデータで結果が変わることを確かめる（生成器の sale_phases、レビューで検出） | 1 | 2026-10-11 | 候補 |
| 定義修正 | 生成器を変えるブリーフの前に、関連する不変条件のテスト（UnsoldMatchTest の「未販売の試合に何も無い」など）を grep して前提に書く | 1 | 2026-10-11 | 候補 |
| 定義修正 | E2E を「その時のデータに合わせて選ぶ」形に直すとき、初期値によって検証が空振りしないか（同じ値への変更、元が buffer のブロックを buffer に塗る）を完了条件に入れる。haiku の修正で 2 か所残り、メインが直した | 1 | 2026-10-11 | 候補 |
| 定義修正 | 閲覧権限を足すブリーフに、未ログインで API を叩いている既存テスト・e2e ヘルパーを grep して影響範囲として書く（PurchaseFoundationTest、SeasonPassIssuerTest、e2e/lib/api.ts が壊れた） | 1 | 2026-10-12 | 候補 |
| 定義修正 | 開発 DB に migrate した後でレビュー指摘によりマイグレーションを直すと、開発 DB とずれる（`season_pass_seats.user_id` の on delete）。スキーマのステップでは、開発 DB への migrate をレビュー通過後にする。2026-10-12: `resale_holds` の索引追加で再発（ユーザー承認で rollback → migrate） | 2 | 2026-10-12 | 採用（`implementer.md` / `step/SKILL.md`） |
| 定義修正 | researcher の設計比較ブリーフで「決めること」11 項目と字数上限 3000 字が衝突し、根拠を書ききれなかった（リセールの設計）。項目数か字数のどちらかを緩める | 1 | 2026-10-12 | 候補 |
| 定義修正 | implementer.md に「status の値を足すときは、その列を列挙・判定する index・CHECK・scope・SQL を grep して監査表を返す」を足す案。リセールでブリーフに入れたら漏れなく出た | 1 | 2026-10-12 | 候補 |
| 定義修正 | implementer.md の定石に「ロックを取るサービスは時刻をロック取得後に内部で取る。`$now` を引数で受けない」を足す案。ResaleService が引数で受けてレビュー（中）で戻した | 1 | 2026-10-12 | 候補 |
| 定義修正 | ブリーフに「読み取り用のフラグ（can_*）は対応する書き込みメソッドと同じ述語を共有する」を書く。can_list は共有、can_cancel は期間を余計に見て食い違った（レビュー中） | 1 | 2026-10-12 | 候補 |
| 定義修正 | api.ts の型を変えるブリーフに「frontend の既存コード（server-api.ts の網羅マップ、楽観更新の仮データ）の最小修正を含む」を書く | 1 | 2026-10-12 | 候補 |
| check | 新しいルートを足した直後は `next typegen` を流さないと `PageProps<"...">` で tsc が落ちる。`scripts/check.sh frontend` の前に typegen を実行する案。2026-10-12: リセール用カートの resale-checkout でも再発 | 2 | 2026-10-12 | 採用（`scripts/check.sh frontend`） |
| 定義修正 | tickets / orders を FK 参照するテーブルを足すブリーフに「`GenerateDemoData` の TRUNCATE 対象に足す」を書く（既存テスト 16 件が落ちた）。2026-10-12: リセール用カートの `resale_holds` でも implementer が気づいて足した | 2 | 2026-10-12 | 採用（`.claude/agents/implementer.md`） |
| check | phpunit の memory_limit 128MB に生成系テストが近い（ピーク 80MB 台、一度超えた）。`phpunit.xml` の ini で上限を上げるか、生成テストを分ける。2026-10-12: ランダム順で `MembershipPlannerTest` が 128MB を超えた（`artisan test` は子プロセスなので `php -d` が効かない） | 2 | 2026-10-12 | 採用（`backend/phpunit.xml` で 512M） |
| 定義修正 | E2E の定石に「繰り返し実行で状態が溜まるもの（購入上限など）をユーザー選定で考慮し、マイチケット等の探索は試合で絞る」を足す案（resale.spec、レビューで中） | 1 | 2026-10-12 | 候補 |
| 定義修正 | 生成器の前後比較は同じシード済み DB の上で行う（`migrate:fresh --seed` のたびに構造データ由来の値がずれる）。生成器を変えるブリーフに書く | 1 | 2026-10-12 | 候補 |
| 定義修正 | 仮押さえを作るブリーフとレビュー観点に「同じ人の取り直し（解放→再取得、期限切れ直後）で占有し続けられないか、制限の有無」を入れる。一次カートでは申し送り、リセールの hold では出品者の取り下げまで止まるため security-reviewer が中で検出し、クールダウンを入れた | 2 | 2026-10-12 | 採用（`code-reviewer.md` / `security-reviewer.md` / `step/SKILL.md`） |
| 定義修正 | code-reviewer の同時実行チェックに「ロック順の部分列でも、ロックを取らずに他の行を更新する整理 UPDATE（期限切れの掃除）は行ロック・部分ユニークの索引待ちで交差しうる」を足す案。code-reviewer は循環しないと判断し、security-reviewer が交差を検出した（リセール hold の expireStale） | 1 | 2026-10-12 | 候補 |
| 定義修正 | security-reviewer（opus）が返却形式を守らず「重大指摘なし」の 1 行だけ返した（リセール用カート S2）。再依頼で出し直した。定義の返却形式に「指摘が無くても観点ごとの確認結果と読んだ範囲を書く」を明記する案 | 1 | 2026-10-12 | 候補 |
| 定義修正 | リセール系のブリーフに「`/api/me/tickets` の応答キーは `data`」を書く（implementer の指摘） | 1 | 2026-10-12 | 候補 |
| 定義修正 | ブリーフが「implementer.md の E2E の定石」を参照したが、implementer.md に E2E の節が無かった（台帳の E2E 定石の行は候補のまま）。E2E の定石を implementer.md に入れるか、ブリーフで参照しない | 1 | 2026-10-12 | 候補 |
| 定義修正 | implementer がコマンドを run_in_background に回したまま、報告せずに終了した（リセール用カート S5 で 2 回続けて。再開依頼でも同じ）。新しい implementer に「すべてフォアグラウンド、run_in_background 禁止」と書いたら完了した。implementer.md に「check・テスト・生成はフォアグラウンド（timeout 600000）」を明記する案（上の P3a の行と方針が逆なので統合して決める） | 1 | 2026-10-13 | 候補 |
| 環境 | app コンテナ内で php の autoload 読み込みが固まり、kill も compose restart も効かなかった（`did not receive an exit event` / `setns`）。Docker Desktop の再起動で復旧。dev-notes の環境のハマりどころに手順を書く案 | 1 | 2026-10-13 | 採用（dev-notes.md 環境の注意点 9） |
| 定義修正 | 記事の公開前チェック（構成メモ）に「記事が案内する立ち上げ手順を、clone 直後の別ディレクトリで通す」を足す案。再構築手順に `composer install` と `cp .env` が無く、public の clone からは通らない見込みだった（1-2 の裏取りで researcher が指摘） | 1 | 2026-10-17 | 候補 |
| 定義修正 | verifier が `scripts/check.sh all` の出力を `tail -n 200` で切り、pint の結果を落とした（メインが pint を再実行して確認）。verifier.md に「各セクションの成否行を拾う（grep で PASS/FAIL を抽出する、など）」を足す案 | 1 | 2026-10-17 | 候補 |
| 定義修正 | 記事の事実確認ブリーフに「数値や向き（A が X、B が Y）は、元のレビュー記録やコミットで裏を取る」を入れる案。dev-notes が「8.4.24 / 8.4.26」と向きを書いておらず、下書きで逆に書いた（1-2 の事実確認で検出）。dev-notes 側にも向きを書いた | 1 | 2026-10-17 | 候補 |
