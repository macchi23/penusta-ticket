# DEA 演習メニュー（ペヌスタのデータで手を動かす）

dea-notes.md が「下した判断の記録」なのに対し、こちらは「これから試す演習の候補」。
DEA-C01 の 4 ドメインごとに、このリポジトリのデータ（2026-10-11 に 7 シーズン化。イベントログ約 506 万行（2026-11-06 実測）、2021-01-10〜2026-11-01 の 1,853 dt（暦日 2,122 日）、RustFS 上で raw 179.65MB / curated 241.2MB（業務テーブル込み、2026-11-16）。[demo-data.md](demo-data.md)）と基盤（`infra/`、`scripts/aws-analytics.sh`）で何をやれば論点を体感できるかを並べる。
終えた演習は dea-notes.md に論点として書き移し、ここでは「済」にする。

## 前提と費用の守り

- 環境の作り方・片付け方は dev-notes.md の「実 AWS（S3 + Glue/Athena）」。毎回 `cdk deploy` → `analytics.sh build` → `aws-analytics.sh sync` で戻し、終わったら `teardown` → `status` で absent を確認する
- 費用の目安: Athena は 1 クエリ最低 10MB（約 $0.00005）。このデータなら 1 回 20MB 前後。WorkGroup のスキャン上限 1GB/クエリが効いている
- 印の意味: 【ローカル】DuckDB だけで完結（無料） / 【Athena】既存スタックで完結（ほぼ無料） / 【追加】新しいサービスを足す（費用と片付けを先に確認。「要確認」の料金は着手前に料金ページで確かめる）
- 新しいサービスを足す演習は infra/ のスタックに入れ、teardown で一緒に消えるようにする。スタック外に作ったものは dev-notes の片付け手順に追記する

## ドメイン 1: 取り込みと変換（Ingestion and Transformation）

| # | 演習 | 印 | 体感できる論点 |
|---|---|---|---|
| 1-1 | raw → curated を Athena の CTAS / `INSERT INTO` で作り、DuckDB 製の curated と `compare` で一致させる | Athena | Glue ETL ジョブを使わない SQL 変換、`from_iso8601_timestamp`、struct からの取り出し |
| 1-2 | 1 日分だけを `INSERT INTO`（日次の増分）→ 同じ日を 2 回流して重複することを確認 → Iceberg の `MERGE INTO` で冪等にする | Athena | 冪等性、追記と upsert の違い（dea-notes 7. / 9.） |
| 1-3 | 遅延到着の再現: `events:export --date` で過去日を出し直し → sync → MERGE で取り込み | Athena | イベント時刻と到着時刻、バックフィル（dea-notes 2. / 7.） |
| 1-4 | payload に新しいキー（例 `coupon_code`）が増えたとき: Hive JSON の struct（未定義キーは無視される）と Iceberg の `ALTER TABLE ADD COLUMNS` で挙動を比べる | Athena | スキーマ進化、クローラを使わない理由（dea-notes 8.） |
| 1-5 | export → sync → Athena の照合を Step Functions + EventBridge Scheduler でつなぐ | 追加（Step Functions の無料枠は要確認） | オーケストレーション、MWAA を使わない理由（dea-notes 13.） |
| 1-6 | Firehose で S3 へ直接ストリーム取り込み（動的パーティショニングで dt を切る）し、日次バッチと比べる | 追加（Firehose は取り込み GB 課金、要確認） | バッチとストリーミング、バッファリングと small files（dea-notes 10. / 5.） |

## ドメイン 2: データストアの管理（Data Store Management）

| # | 演習 | 印 | 体感できる論点 |
|---|---|---|---|
| 2-1 | funnel を `WHERE dt BETWEEN ...` ありとなしで流し、DataScannedInBytes を比べる | Athena | パーティションプルーニング（dea-notes 1.） |
| 2-2 | 「JST の日別購入数」を `jst_date` で絞る場合と `dt`（UTC）で絞る場合で比べる。`jst_date` はパーティションではないので全スキャンになる | Athena | パーティションキーとタイムゾーンの選び方（dea-notes 2.） |
| 2-3 | 同じデータを CTAS で JSON / Parquet（SNAPPY）/ Parquet（ZSTD）に書き分け、同じ集計のスキャン量と S3 サイズを比べる | Athena | 列指向と圧縮（dea-notes 3. / 4.） |
| 2-4 | 日次 1 ファイル（raw/events は 1,853 ファイル・計 137MB・中央 72.5KB、2026-11-16）の小ささを確認し、月単位の CTAS や Iceberg の `OPTIMIZE` で結合して速度を比べる | Athena | small files 問題（dea-notes 5.） |
| 2-5 | partition projection をやめて `ALTER TABLE ADD PARTITION` / `MSCK REPAIR TABLE` で登録し、手間と Glue のオブジェクト数を比べる | Athena | パーティション登録の 3 方式、Data Catalog の無料枠 |
| 2-6 | Iceberg で誤った `DELETE`（例: purchase を消す）→ funnel が崩れる → `FOR VERSION AS OF` の結果を `INSERT` して戻す | Athena | タイムトラベルによる復旧（dea-notes 9.） |
| 2-7 | raw に S3 ライフサイクル（一定日数後に Glacier Instant Retrieval など）を設計だけする。最低保存期間の課金があるので実際には適用しない | ローカル（設計のみ） | ストレージクラスと最低保存期間 |

## ドメイン 3: 運用とサポート（Data Operations and Support）

| # | 演習 | 印 | 体感できる論点 |
|---|---|---|---|
| 3-1 | データ品質チェックを Athena の SQL で書く（`id` の重複、`session_id` の欠損、`occurred_at` の UTC 日付と `dt` のずれ、未来日付）。結果が 0 行であることを compare と同じ形で機械判定する | Athena | データ品質の検査、Glue Data Quality（DQDL）との対比 |
| 3-2 | raw と curated の dt 別件数を突き合わせる照合クエリを作る（今の compare は funnel の結果だけを比べている） | Athena | 層間の reconciliation（dea-notes 6.） |
| 3-3 | WorkGroup のスキャン上限を一時的に 10MB にし、funnel が上限超過で止まることを見る | Athena | コスト統制、WorkGroup の enforce（dea-notes 12.） |
| 3-4 | CloudTrail のイベント履歴で、自分の `StartQueryExecution` や `DeleteStack` を探す | Athena（イベント履歴は無料） | 監査ログ、誰がいつ何をしたか |
| 3-5 | Athena の `EXPLAIN` / `EXPLAIN ANALYZE` で funnel の 3 回の LEFT JOIN を読み、JOIN を減らす書き換えを試す | Athena | クエリのチューニング |

## ドメイン 4: セキュリティとガバナンス（Data Security and Governance）

| # | 演習 | 印 | 体感できる論点 |
|---|---|---|---|
| 4-1 | 「分析者」用の最小権限 IAM ポリシーを書く（curated prefix の GetObject、対象テーブルの glue:Get*、WorkGroup 指定の athena:*）。IAM Policy Simulator で raw や DROP が拒否されることを確かめる | 追加（IAM は無料） | 最小権限。今の SSO ロール（DeveloperAccess）が広すぎるというレビュー指摘への答え |
| 4-2 | Lake Formation にバケットを登録し、`user_id` 列を隠す列レベル権限と、試合ごとの行フィルタを付ける | 追加（Lake Formation 自体は無料、要確認） | 列・行レベルのアクセス制御、LF-Tags（Glue テーブルに CFn タグが付かない問題の代替） |
| 4-3 | curated を作る CTAS で `user_id` を `sha256` で仮名化し、funnel の結果が変わらないことを compare で確かめる | Athena | PII の仮名化（セキュリティレビューの「保持期限・仮名化」指摘） |
| 4-4 | SSE-S3 と SSE-KMS（カスタマー管理キー）の違いを確かめる。KMS キーは月額課金と削除待機期間があるので、作るなら待機期間中の扱いを先に確認 | 追加（KMS は要確認） | 暗号化方式の選び方、Athena の結果の暗号化 |
| 4-5 | Macie で raw をスキャンし、`session_id` などが検出されるか見る | 追加（Macie の課金は要確認） | 機密データの検出 |

## 試験前の短期プラン（2026-11-09 作成）

模擬問題を 1 日 1 セット解いて復習するのが主で、ハンズオンは 1 日 30 分まで。パック 1 を最優先にし、パック 2 以降は余裕があるときだけ進める。全部はやらない。

| 日付 | 模擬問題 | ハンズオン |
|---|---|---|
| 11/9 | セット 1 の復習 | なし |
| 11/10 | セット 2 + 復習 | パック 1 の第 1 回 |
| 11/11 | セット 3 + 復習、セット 1〜3 の平均で受験かリスケかを判断（迷ったら 11/12 の朝に最終決定） | パック 1 の第 2 回 |
| 11/12 | セット 4 + 復習 | パック 1 の第 3 回 |
| 11/13 | 新しいセットは解かず、間違いを見直す | パック 1 の第 4 回 |

進め方:

1. 試験風のクエリや問いを見て、実行前に結果を予想する
2. Claude Code が実行し、結果を見せる
3. 予想が外れた理由を確認し、1 行だけ変えて再実行する。必要なら dea-notes.md に「実機」と明記して追記する
4. 実 AWS を使ったら、teardown → status で absent を確認する

実 AWS への書き込み（deploy / ddl / teardown）は毎回実行前に確認する。実行は Claude Code に任せるが、記事に使う数字は第 2 部を書くときに本人が測り直す。

### パック 1: Athena と Redshift のクエリ（ローカル、0 円、各 30 分）

試験ではクエリを読んで選ぶ力が問われるので、ゼロから書くより結果の予想を繰り返す。SELECT と JOIN の基本は分かる前提で、不安があればそこから始める。

Athena の練習には DuckDB、Redshift の練習には使い捨ての PostgreSQL DB を使う（`penusta` と `penusta_test` は触らない）。どちらも本番と構文の差があるので、最後に Athena / Redshift での書き方を確認する。

| 回 | 内容 | 予想する問い | 抑えること | 環境 |
|---|---|---|---|---|
| 第 1 回 | 集計とウィンドウ関数 | `GROUP BY` と `OVER` で行数はどう変わるか。`ROW_NUMBER` と `RANK` はいつ違うか。`LAST_VALUE` は何を返すか | グループごとの上位 N 件、累計、`LAG` / `LEAD` / `FIRST_VALUE` / `LAST_VALUE`、既定のフレーム | DuckDB |
| 第 2 回 | 分析クエリを読む | `NULL` は集計や比較でどう扱われるか。`LEFT JOIN` の条件を `WHERE` に移すと結果はどう変わるか | CTE、`CASE`、条件付き集計、`NULL` と `JOIN` のひっかけ | DuckDB |
| 第 3 回 | Athena らしい書き方 | 配列を展開すると行数はどうなるか。変換できない値を `CAST` と `TRY_CAST` で読むとどう違うか | パーティションの絞り込み、`CTAS`、`UNNEST`、JSON の取り出し、日時の変換、`TRY_CAST` | DuckDB |
| 第 4 回 | Redshift らしい書き方 | `SELECT` だけ / `USAGE` だけで読めるか。`ALTER DEFAULT PRIVILEGES` は別のユーザーが作ったテーブルにも効くか。`MERGE` の一致と不一致で何が起きるか | `GRANT`、`MERGE`、マテリアライズドビューは実行する。`COPY`、`UNLOAD`、`DISTKEY`、Spectrum、`SUPER`、`PIVOT` は読む | PostgreSQL + 読解 |

実際の Redshift Serverless は短時間でも費用がかかるので使わない（要確認）。

### パック 2: Athena をまとめて（既存スタック、ほぼ無料、60 分、余裕があれば）

1 回の deploy で続けて行い、最後に teardown する。パック 1 の第 3 回で扱った `CTAS` と `UNNEST` も、ここで実 Athena で確認する。AWS SSO のログインとスタックの確認が要る。

| 試すこと | 予想する問い | 抑えること | ドメイン |
|---|---|---|---|
| 演習 2-1 + 2-2: 絞り込みの列によるスキャン量の比較 | `dt` で絞る、`jst_date` で絞る、絞らない、の順でスキャン量はどうなるか | パーティションプルーニング、パーティションキーの選び方 | D2 |
| 演習 2-3: JSON / Parquet のスキャン量の比較 | 1 列だけ集計するとき、形式でスキャン量は何倍変わるか。`SELECT *` でも同じ差が出るか | 列指向と圧縮、コストとの関係 | D2 |
| 演習 3-3: WorkGroup のスキャン上限 | 上限を超えるとクエリはどう止まるか。止まったクエリの料金はどうなるか | コスト統制、enforce | D3 |
| 演習 3-4: CloudTrail のイベント履歴 | 自分のクエリと `DeleteStack` はどう記録されるか。SQL の本文まで見えるか | 監査ログに残るものと残らないもの | D3 |
| Glue Data Catalog の取得（AWS CLI と Boto3） | 件数を絞って `get-tables` を呼ぶと何が返るか。ページネーターとの違いは何か | ページ分割、`NextToken`、`get_paginator` | D1 |

### パック 3: Step Functions と EventBridge（追加、数円程度、60 分、余裕があれば）

infra/ のスタックに足して teardown で一緒に消す。最小の Glue Python Shell ジョブを使う。費用は着手前に料金ページで確かめる。

| 試すこと | 予想する問い | 抑えること | ドメイン |
|---|---|---|---|
| Standard から Glue を `startJobRun` と `.sync` の 2 通りで呼ぶ | 各方式で、後続のステートはいつ始まるか。ジョブが失敗したら実行全体はどうなるか | 統合パターンの違い、失敗の伝わり方 | D1 / D3 |
| Express で `.sync` を定義してみる | どの段階で、どう失敗するか | Standard と Express の違い | D1 |
| `Retry` と `Catch` を付けて失敗させる | 再試行の間隔と回数はどうなるか。`Catch` に入るのはいつか | エラー処理、失敗時の通知 | D3 |
| S3 → EventBridge → CloudWatch Logs | バケットの設定を ON にしただけで何が起きるか。別のプレフィックスに置いたらルールはどう動くか | EventBridge 通知、イベントパターン | D1 |

### パック 4: さらに余裕があれば

上から順に、弱点に近いものを選ぶ。

| 試すこと | 予想する問い | 抑えること | ドメイン | 費用 |
|---|---|---|---|---|
| Kinesis Data Streams に遅い Lambda をつなぐ | どの指標がどう動くか。`ParallelizationFactor` を上げると何が変わり、順序はどうなるか | `IteratorAge`、並列度、パーティションキーと順序 | D1 / D3 | 数円〜数十円（要確認） |
| 演習 4-1 + 4-3: 最小権限の IAM と仮名化 | raw と DROP は拒否されるか。仮名化で funnel の結果は変わるか | 最小権限、Policy Simulator、仮名化 | D4 | ほぼ無料 |
| 演習 1-2 + 2-6: Iceberg の `MERGE` とタイムトラベル | 同じ日を 2 回流すと何が起きるか。誤った `DELETE` はどう戻すか | 冪等性、スナップショット、復旧 | D1 / D2 | ほぼ無料 |
| 演習 2-5: パーティション登録の 3 方式 | `MSCK REPAIR TABLE` が使えないのはどんなパスのときか | 3 方式の使い分け | D2 | ほぼ無料 |

### 試験後に回すもの

時間に対して得られるものが少ないか、片付けに注意が要る。第 2 部の素材にもなる。

- 演習 4-2（Lake Formation）: 試験では重要だが、設定に時間がかかる。試験前は座学と問題演習で押さえる
- 演習 4-4（KMS）、4-5（Macie）: 課金と片付けの確認が要る
- 演習 1-5（Scheduler まで含めた全体の連携）、1-6（Firehose）
- Glue のクローラ、ジョブのブックマーク、Spark の Broadcast と実行計画

Redshift、EMR、MWAA、MemoryDB は常時課金のものが多く、今回は座学と問題演習だけにする。

## おすすめの順番

1. 【Athena】だけで回せるもの: 2-1 → 2-2 → 2-3 → 1-1 → 3-1 → 1-2 → 2-6。デプロイ 1 回でまとめてやり、最後に teardown
2. セキュリティ: 4-3 → 4-1 → 4-2（DEA で配点があり、レビュー指摘ともつながる）
3. 【追加】のうち費用が読めるもの: 1-5 → 1-6

## 問題づくりへのつなぎ方

- 演習を終えたら、dea-notes.md の該当章に「ペヌスタでの使い方 / 判断ルール / ひっかけ」として 1〜3 行足す（dea-notes 冒頭の「使い方」の形式）
- スキャン量や件数など、実機で測った数字は dea-notes に「実機」と明記して残す
