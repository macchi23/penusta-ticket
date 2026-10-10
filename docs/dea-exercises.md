# DEA 演習メニュー（ペヌスタのデータで手を動かす）

dea-notes.md が「下した判断の記録」なのに対し、こちらは「これから試す演習の候補」。
DEA-C01 の 4 ドメインごとに、このリポジトリのデータ（2026-10-11 に 7 シーズン化。イベントログ約 603 万行、2021-01〜2026-10 の約 2,100 日分、RustFS 上で raw 171MB / curated 223MB。[demo-data.md](demo-data.md)）と基盤（`infra/`、`scripts/aws-analytics.sh`）で何をやれば論点を体感できるかを並べる。
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
| 2-4 | 日次 1 ファイル（旧データでは 702 ファイル・計約 12MB・平均 20KB 弱。7 シーズン化後は日数が約 6 倍・1 日あたりも増えたので測り直す）の小ささを確認し、月単位の CTAS や Iceberg の `OPTIMIZE` で結合して速度を比べる | Athena | small files 問題（dea-notes 5.） |
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

## おすすめの順番

1. 【Athena】だけで回せるもの: 2-1 → 2-2 → 2-3 → 1-1 → 3-1 → 1-2 → 2-6。デプロイ 1 回でまとめてやり、最後に teardown
2. セキュリティ: 4-3 → 4-1 → 4-2（DEA で配点があり、レビュー指摘ともつながる）
3. 【追加】のうち費用が読めるもの: 1-5 → 1-6

## 問題づくりへのつなぎ方

- 演習を終えたら、dea-notes.md の該当章に「ペヌスタでの使い方 / 判断ルール / ひっかけ」として 1〜3 行足す（dea-notes 冒頭の「使い方」の形式）
- スキャン量や件数など、実機で測った数字は dea-notes に「実機」と明記して残す
