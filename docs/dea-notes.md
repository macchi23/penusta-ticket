# DEA 学習メモ（ペヌスタでの実装と対応づけ）

AWS Certified Data Engineer – Associate（DEA-C01）の論点を、このリポジトリで実際に下した判断と結びつけて記録する。
タスクを終えるたびに追記する。料金・上限値は記憶ベースのものに「要確認」を付けている（2026-10-05 に確かめたものは「確認済み」に更新。実機で確かめたものは本文に「実機」と書き、それ以外は公式の料金ページ・ドキュメントや技術記事による）。

これから手を動かす演習の候補は dea-exercises.md にまとめている。

## 使い方（問題を作るとき）

- 1 項目 = 1 論点。各項目は次の見出しで揃えている
  - **ペヌスタでの使い方**: 実装ファイルと判断理由。問題の状況設定に使う（ペヌスタ → 架空のチケット会社に置き換える）
  - **AWS での対応**: サービス名・機能名・設定名
  - **判断ルール**: 「条件 → 選ぶもの」を 1 行 1 事実。正解の根拠になる
  - **ひっかけ**: 「誤答 → 誤りの理由」。誤答の選択肢になる
  - **問題文のキーワード**: 正解の向きを決める言い回し
  - **採らなかった選択肢**: 状況を変えると正解になるもの。条件を変えた類題に使う
- 問題を作るときは「状況（ペヌスタでの使い方を AWS に置き換え）+ キーワード 1 つ → 判断ルールから正解 1 つ、ひっかけから誤答 3 つ」にする

---

## 1. パーティショニング（Hive 形式）

**ペヌスタでの使い方**
- `raw/events/dt=YYYY-MM-DD/part-0000.jsonl.gz`（`backend/app/Console/Commands/ExportEvents.php`）
- dt は occurred_at（イベント時刻）の UTC 日付。1 日あたり最大 1,700 行程度なので日単位で十分
- ファイルの中に dt 列は入れない（パスから取る）

**AWS での対応**
- Athena / Glue Data Catalog / Redshift Spectrum が `key=value/` 形式のパスをパーティション列として扱う
- パーティションの登録方法: Glue クローラ、`MSCK REPAIR TABLE`、`ALTER TABLE ADD PARTITION`、partition projection

**判断ルール**
- WHERE 句でよく絞る列（日付など）でパーティションを切る → スキャン量が減り Athena の料金（スキャン量課金）と時間が下がる
- 日付のように規則的に増えるパーティションで、登録の手間をなくしたい → partition projection（Athena のテーブルプロパティで範囲と形式を定義）
- パスが `key=value` 形式 → `MSCK REPAIR TABLE` で一括登録できる
- パスが `2026/08/23/` のように key が無い形式 → `MSCK REPAIR TABLE` は使えない。`ALTER TABLE ADD PARTITION` か partition projection
- パーティション列と同名の列をファイル内に持たない → Athena でエラーになる

**ひっかけ**
- 「毎日 Glue クローラを回して新しいパーティションを登録」→ 動くがコストと遅延が余計。規則的な日付なら partition projection の方が良い
- 「user_id でパーティションを切る」→ 高カーディナリティで small files 問題になる。誤り
- 「partition projection を設定したので Glue ETL（Spark）でも自動で効く」→ partition projection は Athena だけの機能。誤り

**問題文のキーワード**
- 「クエリのスキャン量を減らしたい」「コストを下げたい」→ パーティション + 列指向
- 「新しいパーティションの管理の運用負荷を最小に」→ partition projection

**採らなかった選択肢**
- `dt=/hour=` の 2 段: 1 時間あたりのデータが多い（ストリーミングで大量に入る）場合に使う
- JST 基準の dt: 下の「2. イベント時刻と到着時刻、タイムゾーン」

## 2. イベント時刻と到着時刻、タイムゾーン

**ペヌスタでの使い方**
- dt は「イベントが起きた時刻（occurred_at）」の UTC 日付。エクスポートを実行した時刻ではない
- JST の日付は DuckDB 側で `jst_date` 列として作る（`analytics/build.sql`）
- UTC と JST で日付が変わるイベントは全体の約 37%（76,476 / 204,655）。試合単位の分析は match_id で絞るので dt の基準に依存しない

**AWS での対応**
- Firehose の S3 プレフィックス（既定 `YYYY/MM/DD/HH/`）は **到着時刻の UTC** で切られる
- イベント時刻やレコード内の値で切りたい → Firehose の動的パーティショニング（JQ 式でキーを取り出す。追加料金あり）
- Kinesis Data Streams のレコードには `ApproximateArrivalTimestamp`（到着時刻）が付く

**判断ルール**
- 「その日に起きたこと」で集計したい → イベント時刻でパーティションを切る
- 遅れて届くデータ（オフライン端末など）がある → 到着時刻で受けて、後段でイベント時刻に並べ直す。または過去パーティションを再処理できる設計にする
- タイムゾーンは保存も分割も UTC に揃え、表示や集計のときにローカル時刻へ変換する

**ひっかけ**
- 「Firehose の既定プレフィックスでイベント発生日ごとに分かれる」→ 到着時刻で分かれる。誤り
- 「ローカル時刻でパーティションを切れば集計が楽」→ 夏時間や複数地域で境界がずれる。UTC が定石

**問題文のキーワード**
- 「イベントの発生日でパーティション分割したい」「レコード内の属性で S3 プレフィックスを分けたい」→ Firehose 動的パーティショニング
- 「遅延して到着するデータ」→ 再処理可能な設計、イベント時刻と処理時刻の区別

**採らなかった選択肢**
- JST 基準の dt: 利用者がすべて JST で日次集計中心なら合理的。ただし保存（UTC）と基準が二重になる

## 3. ファイル形式（行指向と列指向）

**ペヌスタでの使い方**
- PHP からは JSON Lines（行指向）で出す。PHP で Parquet を書くのは現実的でないため
- DuckDB が JSON Lines を読んで Parquet（列指向）に変換し、`curated/events/dt=*/part-0.parquet` に書く（`analytics/export_curated.sql`）
- payload（JSON）は Parquet では VARCHAR として書く（Athena / Glue が JSON 論理型を扱えない可能性があるため）

**AWS での対応**
- 変換の手段: Athena CTAS / `INSERT INTO`、Glue ETL ジョブ、Firehose のレコード形式変換（JSON → Parquet / ORC。Glue テーブルのスキーマを使う）
- Parquet / ORC は列ごとに min / max などの統計を持ち、述語プッシュダウンで読み飛ばせる

**判断ルール**
- 分析クエリで一部の列だけ読む → 列指向（Parquet / ORC）。スキャン量が減る
- 取り込みの直後（ランディング）は人が読める・どこからでも書ける形式（JSON / CSV）、分析用には列指向に変換して別の層に置く
- Firehose で JSON を受けて Parquet で S3 に置きたい → Firehose のレコード形式変換（Glue Data Catalog のテーブル定義が必要）
- Athena CTAS / INSERT INTO は 1 回のクエリで書けるパーティション数に上限がある（100、確認済み 2026-10-05）→ 多数の日付をまとめて変換するときは分割して実行

**ひっかけ**
- 「CSV を gzip 圧縮すれば Parquet と同等にスキャン量が減る」→ 圧縮しても全列を読む。列の読み飛ばしはできない
- 「Firehose のレコード形式変換は Glue のテーブル定義なしで使える」→ スキーマ参照に Glue テーブルが必要。誤り

**問題文のキーワード**
- 「特定の列だけを集計」「Athena のコストを下げたい」→ Parquet / ORC
- 「取り込み時に変換、最小のコードで」→ Firehose のレコード形式変換

**採らなかった選択肢**
- PHP から直接 Parquet: ライブラリが乏しい。言語が Python / Java / Spark なら取り込み時に直接 Parquet が普通
- ORC: Hive / Presto 系では同等。エコシステムの広さで Parquet を選んだ

## 4. 圧縮

**ペヌスタでの使い方**
- raw の JSON Lines は gzip（`.jsonl.gz`）。DuckDB も Athena も拡張子で自動判定する
- Parquet は DuckDB の既定の圧縮（Snappy、要確認）

**AWS での対応**
- Athena / Glue / EMR は gzip、bzip2、Snappy、ZSTD、LZO などを読める

**判断ルール**
- テキスト形式の大きなファイルを並列に読みたい → gzip は分割（split）できないので 1 ファイルを 1 タスクでしか読めない。bzip2 は分割可能だが遅い
- Parquet / ORC はファイル内部でページ単位に圧縮するので、圧縮方式に関係なく分割して読める
- 圧縮率重視 → gzip / ZSTD、速度重視 → Snappy

**ひっかけ**
- 「数 GB の gzip CSV を 1 ファイルで置けば Spark が並列に読む」→ gzip は分割できない。ファイルを分けるか列指向に変換する
- 「Parquet に gzip を使うと分割できなくなる」→ Parquet 内部の圧縮なので分割できる。誤り

**問題文のキーワード**
- 「ジョブが 1 つのタスクだけで遅い」「大きな gzip ファイル」→ ファイル分割 or 分割可能な形式

**採らなかった選択肢**
- 非圧縮の JSON Lines: ストレージのコンソールで中身を見やすいだけ。スキャン量・保存量で不利

## 5. ファイルサイズ（small files 問題）

**ペヌスタでの使い方**
- 1 dt = 1 ファイル。1 日分が gzip で約 400KB（旧データでの最大。7 シーズン化後も 1 日の最大は数 MB 程度の見込みで、未計測）なので分割しない
- DuckDB の Parquet 出力も 1 dt 1 ファイルになることを確認した

**AWS での対応**
- Athena のパフォーマンス指針では、ファイルが小さすぎる（128MB 未満が目安、要確認）とファイルを開くオーバーヘッドが支配的になる
- 小さなファイルをまとめる手段: Glue ETL での coalesce / repartition、Athena CTAS で書き直し、Iceberg の `OPTIMIZE`（compaction）、Firehose のバッファ（サイズ・時間）を大きくする

**判断ルール**
- 大量の小さなファイルでクエリが遅い → まとめる（compaction）
- Firehose の出力ファイルが小さすぎる → バッファサイズ・バッファ間隔を大きくする
- パーティションを細かく切りすぎて 1 パーティションのデータが少ない → パーティションの粒度を粗くする

**ひっかけ**
- 「並列度を上げるためにファイルを細かく分ける」→ 小さすぎると逆効果
- 「Athena のワークグループの設定でファイル数の影響をなくせる」→ そういう設定はない

**問題文のキーワード**
- 「数百万の小さなファイル」「ストリーミングで細かく出力される」→ compaction、バッファ拡大

**採らなかった選択肢**
- 件数で分割（part-0000, part-0001, ...）: 1 日のデータが数百 MB を超えるなら分割する。今回は残骸を消す処理だけ先に入れてある

## 6. データレイクの層（raw / curated）

**ペヌスタでの使い方**
- `raw/events/`: 取り込んだまま（gzip JSON Lines）。E2E 由来のデータも消さない
- `curated/events/`: Parquet に変換したもの。raw からいつでも作り直せる
- 除外（E2E、session_id = 'unknown'）は分析側の `events_clean` ビューで行う

**AWS での対応**
- 一般的な呼び名: raw（landing / bronze）→ cleaned / processed（silver）→ curated（gold）
- 層ごとに S3 プレフィックスかバケットを分け、Lake Formation や IAM で権限を分ける

**判断ルール**
- 生データは加工せず保持 → 変換ロジックの誤りに気づいたとき再処理できる
- 層ごとにアクセス権限を分ける（分析者は curated だけ読める、など）→ Lake Formation
- 古い raw はライフサイクルで安価なストレージクラスへ（S3 Standard-IA / Glacier）

**ひっかけ**
- 「容量削減のため変換後に raw を削除」→ 再処理できなくなる。ライフサイクルで安価な層へ移すのが定石

**問題文のキーワード**
- 「元データを保持しつつ」「再処理できるように」→ raw 層を残す

**採らなかった選択肢**
- 3 層（raw / processed / curated）: 今回は変換が 1 段なので 2 層で足りる。dbt を入れる段階で中間層ができる

## 7. 冪等性・再実行・バックフィル

**ペヌスタでの使い方**
- `events:export --date=` を何度実行しても同じ結果になる。固定キーに上書き → 同じ dt 配下の他キーを削除
- 書き込みに失敗したら既存ファイルを消さずに終了する（壊れたファイルで上書きしない）
- 過去分の一括出力は `--from` / `--to`
- curated は「ローカル DB を作る → 成功したら curated を削除 → 書き出し」の順（`scripts/analytics.sh build`）

**AWS での対応**
- Glue ジョブブックマーク: 処理済みのデータを記録し、次回は新しいデータだけ処理する。過去分をやり直すときはブックマークをリセット（または巻き戻す）
- Athena `INSERT OVERWRITE` はない（要確認）。上書きは対象プレフィックスを消してから `INSERT INTO`、または Iceberg の `MERGE INTO` / `INSERT OVERWRITE` 相当
- Spark の動的パーティション上書き（`partitionOverwriteMode=dynamic`）で、書いたパーティションだけを置き換える

**判断ルール**
- 同じ入力で再実行しても重複しない設計にする → 上書き単位（パーティション）を決めて、その単位で置き換える
- Glue で毎回新しいファイルだけ処理したい → ジョブブックマーク
- 過去データを修正して再処理したい → ブックマークをリセットして対象範囲を再実行

**ひっかけ**
- 「ジョブブックマークを有効にしておけば、修正したロジックで過去分も自動で再処理される」→ 処理済みは飛ばされる。誤り
- 「S3 に追記（append）し続ければ再実行しても安全」→ 重複する

**問題文のキーワード**
- 「重複を防ぎたい」「ジョブが失敗して再実行した」「新しいデータだけ処理」→ 冪等な上書き、ジョブブックマーク

**採らなかった選択肢**
- 「先に dt を消してから書く」: 消してから書くまでの間、その日のデータが見えない時間ができる

## 8. スキーマ推論と型の指定

**ペヌスタでの使い方**
- DuckDB の `read_json` で列の型を明示した（`analytics/build.sql`）。自動推論は先頭の標本だけを見るため、まれにしか出ないキーで型がぶれる
- payload は JSON 型で読み、必要なキー（match_id）だけ列に取り出す

**AWS での対応**
- Glue クローラは標本からスキーマを推論し、Data Catalog にテーブルを作る
- パスごとにスキーマが違うと、クローラが複数のテーブルを作ることがある →「各 S3 パスに単一のスキーマを作成」オプション、または除外パターン
- 型が混在する列は Glue DynamicFrame の `ResolveChoice` で解決する
- スキーマ定義を固定したい → クローラを使わずテーブルを DDL / IaC で定義、またはスキーマ変更時の更新動作（更新しない / ログのみ）を設定

**判断ルール**
- スキーマが安定していて型を確定させたい → クローラに頼らずテーブル定義を明示
- スキーマが変わりうる / 未知のデータを探索する → クローラ
- 同じ列に文字列と数値が混在 → `ResolveChoice`（cast / make_struct など）

**ひっかけ**
- 「クローラを毎時実行すればスキーマの不整合は解消する」→ 推論結果が揺れるだけ。誤り
- 「raw と curated を同じ親プレフィックスに置いてクローラを向ける」→ JSON と Parquet のスキーマが混ざる。層ごとにテーブルを分ける

**問題文のキーワード**
- 「クローラが想定外に複数のテーブルを作成した」→ 単一スキーマのオプション、除外パターン、パス構成の見直し
- 「列の型が一致しないエラー」→ `ResolveChoice`、型の明示

**採らなかった選択肢**
- キー名に `format=jsonl/` を入れる: DuckDB や Athena が `format` をパーティション列と誤認するおそれがあるので、層は `raw/` `curated/` の名前で分けた

## 9. S3 の上書きとトランザクション（Iceberg）

**ペヌスタでの使い方**
- curated の作り直しは「プレフィックスを消す → 書く」で、その間に読むと欠けたデータが見える（ローカルの遊びなので許容）
- DuckDB の S3 へのパーティション書き込みは上書き（OVERWRITE）に対応していないため、先に `aws s3 rm` で消している
- 実 AWS（2026-10-05）では curated の Parquet（Hive テーブル `events_curated`）とは別に、Athena の CTAS で Iceberg テーブル `events_iceberg` を作って試した（`analytics/athena/iceberg/`、詳細は 14.）

**AWS での対応**
- S3 は 1 オブジェクトの PUT は原子的で、2020-12 以降は強い read-after-write 整合性。ただし複数オブジェクトをまとめて入れ替えるトランザクションはない
- Apache Iceberg（Hudi、Delta Lake も同種）: テーブルのメタデータ（どのファイルが現在のテーブルか）をカタログで管理し、スナップショットを切り替えて ACID を実現
- Athena の Iceberg 対応: `MERGE INTO`、`UPDATE`、`DELETE`、タイムトラベル（`FOR TIMESTAMP AS OF` / `FOR VERSION AS OF`）、`OPTIMIZE ... REWRITE DATA USING BIN_PACK`（compaction）、`VACUUM`（古いスナップショットとファイルの削除）
- S3 Tables: Iceberg を AWS がマネージドで提供するテーブルバケット（compaction などを自動化）

**判断ルール**
- データレイク上で行単位の更新・削除（GDPR の削除要求、CDC の反映）をしたい → Iceberg（Athena の `MERGE INTO` / `DELETE`）
- 書き込み中の読み取りで中途半端な状態を見せたくない → Iceberg（スナップショット分離）
- 過去の時点のデータを再現したい → Iceberg のタイムトラベル
- Iceberg で小さなファイルが増えた → `OPTIMIZE`、古いスナップショットが溜まった → `VACUUM`

**ひっかけ**
- 「S3 バージョニングを有効にすればテーブル単位のトランザクションになる」→ オブジェクト単位の履歴であり、複数ファイルの一貫性は保証しない
- 「Hive 形式のテーブルに Athena で UPDATE / DELETE できる」→ できない。Iceberg テーブルが必要

**実機で確かめたこと（2026-10-05、Athena / us-east-1）**
- CTAS で Hive テーブルから Iceberg に変換できる。ただし Hive の timestamp（精度 3 固定）のままだと `Timestamp precision (3) not supported for Iceberg`。`CAST(... AS timestamp(6))` が必要
- 初回 CTAS で append のスナップショット 1 つ。`MERGE INTO`（2026-01-10 の 233 行）で overwrite のスナップショットが増え、件数は変わらない（冪等）
- time travel（`FOR VERSION AS OF <最初の snapshot_id>`）は変更前の状態を返す。件数だけならメタデータで答えるのでスキャン 0 バイト
- `OPTIMIZE` で replace スナップショットができ、データファイルが束ねられる。`VACUUM` はテーブルプロパティの snapshot age（60 秒に設定）を過ぎた古いスナップショットとファイルを消す（スナップショット 3 → 1）
- OPTIMIZE / VACUUM は SQL の先頭にコメント行があると Trino パーサに回され `MALFORMED_QUERY`（mismatched input 'OPTIMIZE'）になる。CTAS / MERGE / ALTER はコメント付きでも通った
- Iceberg の DROP TABLE（Athena）はデータも消える

**問題文のキーワード**
- 「レコード単位の更新・削除」「ACID」「タイムトラベル」「ユーザーのデータを削除する要求」→ Iceberg（Athena / Glue）
- 「運用負荷を最小に Iceberg を使いたい」→ S3 Tables（要確認）

**採らなかった選択肢**
- ローカルで Iceberg（REST カタログ + DuckDB、または PyIceberg + SQLite カタログ）: 仕組みを手元で確かめられるが今回は実 AWS（Athena）で試した

## 10. バッチとストリーミング

**ペヌスタでの使い方**
- 日次のバッチエクスポート（`events:export`）。リアルタイム性は不要（分析は翌日で十分）
- スケジュール実行はまだない（scheduler サービス未導入）

**AWS での対応**
- ストリーミング取り込み: Kinesis Data Streams（シャード単位で順序保証、保持期間中は再生可能）、Amazon Data Firehose（バッファして S3 / Redshift / OpenSearch などへ配送、ほぼリアルタイム、再生不可）、MSK（Kafka）
- バッチのスケジュール: EventBridge Scheduler、Glue のトリガー / ワークフロー、Step Functions、MWAA（Airflow）

**判断ルール**
- S3 に置くだけでよい、変換は最小、運用負荷を最小 → Firehose
- 複数の消費者が同じストリームを読む、順序保証、再生（リプレイ）が必要 → Kinesis Data Streams
- 既存の Kafka 資産 → MSK
- 単純な定期実行 → EventBridge Scheduler。複数ステップの依存・分岐・リトライ → Step Functions。既存の Airflow DAG を移す → MWAA

**ひっかけ**
- 「Firehose で過去のデータを再処理（リプレイ）」→ Firehose には再生機能がない。Kinesis Data Streams の保持期間内なら可能
- 「数分おきの単純なジョブのために MWAA を立てる」→ 常時課金で過剰。EventBridge Scheduler で足りる

**問題文のキーワード**
- 「ほぼリアルタイム」「最小の運用負荷で S3 へ」→ Firehose
- 「リアルタイム」「複数のアプリケーションが消費」「順序」→ Kinesis Data Streams

**採らなかった選択肢**
- ストリーミング: イベントの発生がアプリ内で完結しており、日次で足りるため

## 11. 認証情報の扱い

**ペヌスタでの使い方**
- DuckDB の S3 シークレットは毎回一時的に作る（`CREATE SECRET`）。`CREATE PERSISTENT SECRET` は平文でディスクに保存されるので使わない
- 認証情報はローカル開発用の固定値（RustFS のアクセスキー）
- 実 AWS（2026-10-05）は IAM Identity Center（SSO）の一時認証情報。`personal-dev-admin` プロファイルを `--profile` 引数で渡し、環境変数のキーは外す（詳細は 14.）

**AWS での対応**
- 実行環境（Glue ジョブ、Lambda、EC2、ECS）には IAM ロールを付け、アクセスキーを埋め込まない
- DB のパスワードなどは Secrets Manager（自動ローテーションあり）か Parameter Store（SecureString）
- データへの細かいアクセス制御（列・行・セル単位）は Lake Formation

**判断ルール**
- AWS のサービスから AWS のリソースにアクセス → IAM ロール
- DB の認証情報を定期的に自動で入れ替えたい → Secrets Manager
- 分析者ごとに見せる列・行を変えたい → Lake Formation のデータフィルタ

**ひっかけ**
- 「Glue ジョブのスクリプトや環境変数にアクセスキーを書く」→ 誤り。ロールを使う
- 「Parameter Store で自動ローテーション」→ 自動ローテーションは Secrets Manager の機能

**実機で確かめたこと（2026-10-05）**
- aws CLI は `AWS_PROFILE` を環境変数で渡すと、環境変数の `AWS_ACCESS_KEY_ID` 等が優先されうる。`--profile` 引数なら無視される
- CDK（JS SDK）は同名プロファイルの static キーを優先しうる。そのため teardown は `aws cloudformation delete-stack` に一本化した
- `AWS_ENDPOINT_URL*` や config の `endpoint_url`（ローカルの RustFS 向け）が実 AWS 呼び出しに混ざる。`AWS_IGNORE_CONFIGURED_ENDPOINT_URLS=true` で無視できる
- Laravel の s3 ディスクには token のキーが無く、`AWS_SESSION_TOKEN` が落ちる。SSO の一時認証情報では動かないので、`events:export` の出力先は実 S3 に切り替えていない
- 操作前に `sts get-caller-identity` 相当で Account と Arn（`AWSReservedSSO_`）を確認してから書き込む

**問題文のキーワード**
- 「認証情報をコードに埋め込まずに」「自動ローテーション」→ Secrets Manager
- 「列レベル」「行レベルのアクセス制御」→ Lake Formation

**採らなかった選択肢**
- 環境変数でシークレットを渡す: ローカルでは十分だが、AWS ではロールに置き換える

## 12. コストとリージョン

**ペヌスタでの使い方**
- 実 AWS は us-east-1 か us-west-2（レイテンシ不問、単価が安く新機能が早い）
- 常時課金のサービス（MWAA、EMR クラスタ、Redshift プロビジョンド）は座学のみ

**AWS での対応（料金はすべて要確認）**
- 従量で安い: S3、Athena（スキャン 1TB あたり $5、1 クエリ最低 10MB。確認済み 2026-10-05）、Glue Data Catalog（100 万オブジェクト・月 100 万リクエストまで無料。確認済み）、Glue ETL（DPU 時間課金）、Firehose（取り込み GB 課金）、Lambda、Step Functions、EventBridge Scheduler
- 時間課金で放置すると高い: Kinesis Data Streams（シャード時間）、Redshift Serverless（RPU 時間、使った時だけ）、MWAA（環境の時間課金、最小構成でも月数百ドル）、EMR（インスタンス時間）

**判断ルール**
- Athena のコストを下げたい → パーティション、列指向、圧縮（スキャン量を減らす）
- 使うときだけ課金されたい → サーバーレス（Athena、Glue、Redshift Serverless、EMR Serverless）
- アクセス頻度の低い古いデータ → S3 ライフサイクルで Standard-IA / Glacier へ。アクセスパターンが不明 → Intelligent-Tiering

**ひっかけ**
- 「Athena の料金は実行時間で決まる」→ スキャン量で決まる（プロビジョンド容量を使う場合を除く）
- 「DDL や失敗したクエリも課金される」→ 無課金。キャンセルしたクエリはスキャンした分が課金される
- 「月に数回のクエリのために Redshift プロビジョンドクラスタを常時起動」→ Athena か Redshift Serverless

**問題文のキーワード**
- 「最もコスト効率が良い」→ サーバーレス、スキャン量の削減、ライフサイクル
- 「アクセスパターンが予測できない」→ S3 Intelligent-Tiering

## 13. オーケストレーション / スケジュール実行

**ペヌスタでの使い方**
- compose の `scheduler` サービスで `php artisan schedule:work` を常駐させる。定義は `backend/routes/console.php`
- `events:export` は毎日 00:30 UTC に前日（UTC）分を出す。イベントは記録時刻＝サーバー時刻で、遅れて届くのは秒単位なので 30 分待てば確定する
- `withoutOverlapping(60)` で二重起動を防ぐ（ロックは Redis）。既定のロック期限 24 時間だと、強制終了で残ったロックが翌日分を止めるので 60 分に縮めた
- `tickets:expire-holds` は毎分。1 件ずつ `FOR UPDATE SKIP LOCKED` で回収するので並走しても安全で、ロックは付けない
- 失敗は `onFailure` でコマンドの出力ごと `Log::error` → stderr → `docker compose logs scheduler`
- 止まっていた間の分は取り戻さない（cron と同じ）。取りこぼした日は `--date` で手動で出し直す。冪等な上書きなので何度出してもよい（7. 参照）

**AWS での対応**
- 時刻起動: EventBridge Scheduler（cron / rate / 1 回限り、タイムゾーン指定、flexible time window、リトライと DLQ、at-least-once 配信）。ターゲットに ECS RunTask、Lambda、Step Functions StartExecution など
- 手順の連鎖: Step Functions（Standard）。`export（ECS RunTask .sync）→ Athena クエリ（.sync）→ 通知` のように並べる
- Glue だけで閉じる連鎖: Glue Workflows + Triggers（scheduled / on-demand / conditional / EventBridge イベント）
- 既存の Airflow DAG を移す、OSS との可搬性: MWAA
- ペヌスタを載せるなら: EventBridge Scheduler（毎日 00:30 UTC）→ Step Functions → ECS RunTask `.sync` で `artisan events:export`。新パーティションは partition projection（1. 参照）でクローラ不要。expire-holds は毎分 RunTask だと起動コストに見合わないので、常駐の ECS サービスに残すか、リクエスト時の期限判定だけにする

**判断ルール**
- 決まった時刻に 1 つのジョブを起動 → EventBridge Scheduler
- 複数のステップを順に実行、失敗時のリトライや分岐を定義したい → Step Functions
- Glue ジョブ・クローラだけの依存関係 → Glue Workflows（外部処理を挟むなら Step Functions）
- Step Functions から完了を待って次へ進みたい → `.sync`（ジョブ実行）統合。Glue は `StartJobRun.sync` が使える
- 実行時間が 15 分を超える処理 → Lambda ではなく ECS タスク / Glue ジョブ（Lambda の上限は 15 分。要確認）
- 二重起動を防ぎたい → ジョブ側でロック・冪等化。Scheduler は at-least-once なので同じ起動が 2 回届きうる

**ひっかけ**
- 「EventBridge のスケジュールルール（cron 式のルール）で JST 9:30 に起動」→ ルールは UTC 固定で、現在は Scheduler が推奨（ルールの schedule はレガシー扱い）。タイムゾーン指定が要るなら Scheduler
- 「Step Functions で Glue クローラを `.sync` で待つ」→ クローラは SDK 統合で `.sync` が無い。`StartCrawler` の後に Wait + `GetCrawler` のループ
- 「Express ワークフローで ECS タスクの完了を `.sync` で待つ」→ Express は `.sync` 不可。Standard を使う
- 「Glue Workflows で ECS タスクや Lambda を起動」→ Glue の job / crawler しか起動できない
- 「conditional trigger だけで連鎖を始める」→ 連鎖の起点は scheduled / on-demand（または EventBridge）トリガー。conditional はトリガー経由で起動した job / crawler の完了しか見ない
- 「運用負荷を最小にしたいので MWAA」→ 環境の管理と常時課金がある。単純な定時実行なら Scheduler、連鎖なら Step Functions
- 「S3 にファイルが置かれたら起動」をスケジュールで解く → イベント駆動（EventBridge のイベントパターン / S3 イベント通知）が正解

**問題文のキーワード**
- 「毎日決まった時刻に」「タイムゾーンを指定して」→ EventBridge Scheduler
- 「複数のステップを順番に」「失敗時にリトライ・通知」「視覚的なワークフロー」→ Step Functions
- 「既存の Airflow DAG」「オープンソースのオーケストレーター」→ MWAA
- 「Glue ジョブとクローラの依存関係」→ Glue Workflows
- 「ファイルが到着したら」→ イベント駆動（S3 イベント / EventBridge ルールのイベントパターン）

**採らなかった選択肢**
- cron コンテナ（crond / supercronic で毎分 `schedule:run`）: `schedule:work` と挙動が同じで、環境変数の受け渡しやログ出力の手間が増えるだけ
- MWAA: 常時課金（12. 参照）。DAG が多く依存が複雑なら正解になる
- Glue Workflows: export は ECS / Laravel 側の処理で Glue の外にある。全工程が Glue ジョブなら正解になる
- 毎時、前日分を出し直す: 取りこぼしには強いが、上書きが 1 日 24 回になる。Scheduler のリトライ + 冪等な上書きで足りる

## 14. 実 AWS でのカタログとクエリ基盤（S3 + Glue + Athena）

2026-10-05 に us-east-1 に CDK（`infra/`）で作って確かめた。

**ペヌスタでの使い方**
- Glue DB `penusta_analytics`、テーブル `events_raw`（Hive JSON SerDe、payload は struct、occurred_at は ISO8601 文字列）と `events_curated`（Parquet）を CfnTable で定義。パーティション列 dt は partition projection（2025-12-01〜2026-12-31 の固定範囲）
- `events_iceberg` は Athena の CTAS で作る（CFn の外）
- Athena WorkGroup `penusta-analytics`: engine v3、クエリ結果は Athena 管理（結果バケット不要、24 時間で消える）、スキャン上限 1GB / クエリ + enforce
- S3 は BPA、SSE-S3、SSL 強制、versioning なし、中断マルチパートは 1 日で破棄。タグ `project=penusta-analytics` を App に 1 か所
- 実績（2026-10-05・旧データ 20 万行。7 シーズン化後は未計測）: funnel を DuckDB と照合して一致（events_curated 19.5MB スキャン・3.1 秒 / events_raw 19.7MB・5.4 秒 / events_iceberg 12.6MB）。1 クエリの課金最小は 10MB で、全体で $0.001 未満の見込み

**AWS での対応**
- パーティションの登録: partition projection / クローラ / `MSCK REPAIR TABLE` / `ALTER TABLE ADD PARTITION`（1. 参照）
- JSON の SerDe: Hive JSON SerDe と OpenX JSON SerDe。ドキュメントは OpenX が結果が非決定的になりうると警告しているので Hive JSON を選んだ
- Athena の管理するクエリ結果（managed query results）: 結果バケットを指定しなくてよい
- WorkGroup の「クエリあたりのデータ使用量制限」+ 設定の強制（enforce）で、超えたクエリを打ち切る
- CDK のデプロイ: bootstrap（CDKToolkit スタック）を使う標準のシンセサイザと、使わない `LegacyStackSynthesizer`

**判断ルール**
- 日付パーティションの範囲が決まっている → partition projection に固定範囲を書く。`NOW+2YEARS` のような広い範囲は候補日ごとに S3 LIST が走る（funnel 1 回で約 4,000 LIST、無料枠 2,000/月を超えた）→ データを覆う範囲にする
- スキーマが決まっていて IaC で管理したい → テーブルを CfnTable で定義する。クローラは使わない（DPU 時間課金・最低 10 分、型推論が揺れる、テーブルがスタックの外にできる。最低 10 分は確認済み）
- Iceberg テーブルを CFn（`OpenTableFormatInput`）で持たない → 書き込みごとに metadata_location が変わりドリフトする。Athena の DROP TABLE でデータも消えるので Athena 側で作って消す
- スタックにアセット（Lambda 等）が無い → bootstrap なしでデプロイできる（`LegacyStackSynthesizer`）。標準のシンセサイザでは CDKToolkit のステージングバケットが Retain + versioning で残る。`autoDeleteObjects` は Lambda アセットなので bootstrap 必須で、ロググループも残る
- クエリの暴走を防ぎたい → WorkGroup のスキャン量上限を enforce
- Athena の課金: スキャン量 $5/TB、MB 切り上げ、1 クエリ最低 10MB、無料枠なし。DDL と失敗クエリは無課金、キャンセルはスキャン分課金。Parquet・パーティション絞り込み・列の絞り込みでスキャンを減らす
- Iceberg の MERGE は merge-on-read 型で、小さな差分ファイルが増える → `OPTIMIZE` で束ね、`VACUUM` で古いスナップショットを消す（9. 参照）
- DuckDB など別エンジンの SQL を Athena（Trino）に移すときは方言を直す: `GROUP BY ALL` は Trino では別の意味（列を明示する）、整数どうしの割り算は切り捨て（`CAST(... AS double)`）。`FILTER` / `GROUPING` / `ROLLUP` はそのまま通った
- タグでリソースを探す・コスト配分する → 付けられるリソースにだけ付く。Glue の Database / Table は CFn にタグ属性が無く付かない。タグ検索だけでは残存確認にならない

**ひっかけ**
- 「Glue クローラで毎日パーティションを登録するのが運用負荷最小」→ partition projection ならクローラ不要
- 「partition projection の範囲は広めにしておくと安全」→ 範囲の候補ごとに S3 LIST が走り、無料枠を超える
- 「Athena の DDL（CREATE TABLE）はスキャン量で課金される」→ 無課金
- 「Athena のクエリ結果の保存先は必ずバケットを用意する」→ 管理されたクエリ結果なら不要
- 「Iceberg テーブルの DROP でデータは残る」→ Athena の Iceberg では消える
- 「CDK の `autoDeleteObjects` は bootstrap 不要」→ カスタムリソース（Lambda アセット）を使うので bootstrap が要る

**問題文のキーワード**
- 「スキーマが固定」「IaC」「運用負荷を最小」→ テーブル定義 + partition projection
- 「クエリごとのスキャン量を制限」「コスト管理」→ WorkGroup のデータ使用量制限
- 「JSON を Athena で読む」→ SerDe の指定（Hive JSON / OpenX）

**採らなかった選択肢**
- Glue クローラ: スキーマが未知で探索したいときの手段。今回は列も型も決まっている
- Iceberg テーブルを CFn で作る: Glue カタログだけで管理する場合や書き込みがほぼ無い場合は選択肢になるが、ドリフトの扱いが要る
- 標準のシンセサイザ（bootstrap あり）: Lambda などのアセットが要るときは必須
- export 先を実 S3 に切り替える: app と scheduler が `.env` を共有しており、毎日実 AWS に書くことになる。SSO の期限切れと s3 ディスクの token キー欠落で動かない。今は RustFS → sync の 2 段

## 15. サービス選択の比較（問題演習から）

2026-11-01 から、比較に絞った状況問題を解いて足していく章。ペヌスタで使っていないサービスが中心なので「ペヌスタでの使い方」は、関係があるときだけ書く。

### 15-1. ストリームの取り込みと処理（Kinesis Data Streams / Firehose / Managed Service for Apache Flink）

**ペヌスタでの使い方**
- 今はイベントログを 1 日 1 回 `events:export` で S3 互換ストレージに書くバッチだけ（10. バッチとストリーミング）。試合ごとの販売枚数をリアルタイムに出すなら、Kinesis Data Streams + Managed Service for Apache Flink になる

**判断ルール**
- 複数のコンシューマーが独立に読む → Kinesis Data Streams。読む位置はコンシューマーごと（KCL は DynamoDB に保存）。データは読んでも消えない
- キーごとの順序 → Kinesis Data Streams のパーティションキー。同じキーは同じシャードに入り、シャード内はシーケンス番号順
- 読み直し（リプレイ） → Kinesis Data Streams の保持期間（既定 24 時間、延長で 7 日、最大 365 日）。AT_TIMESTAMP / TRIM_HORIZON から読める
- 複数コンシューマーで低遅延 → 拡張ファンアウト。コンシューマーごとにシャードあたり 2MB/秒の専用の帯域、push 配信。無いとシャードあたり 2MB/秒と GetRecords 毎秒 5 回を取り合う
- S3 / Redshift / OpenSearch に届ける、運用を最小に → Amazon Data Firehose。1 配信ストリームに宛先 1 つ
- JSON を Parquet / ORC にして S3 へ → Firehose のレコード形式の変換（Glue Data Catalog のテーブル定義を使う）
- ウィンドウ集計（タンブリング / スライディング / セッション）、リアルタイム分析、ストリームに SQL → Managed Service for Apache Flink（旧 Kinesis Data Analytics）。イベント時刻とウォーターマークを扱える

**ひっかけ**
- 「Firehose で複数のアプリに配る」→ Firehose は宛先に届けるだけで、アプリが位置を持って読む・読み直すことはできない
- 「SQS の標準キュー + SNS のファンアウトで順序と読み直し」→ 標準キューは順序を保証しない。処理したら削除するので巻き戻せない（FIFO でも読み直しは不可）
- 「S3 のイベント通知でストリーム処理」→ 単位はオブジェクトでイベントではない。1 秒未満の遅延は無理
- 「Firehose → S3 → Athena を毎分でリアルタイム集計」→ バッチの繰り返し。遅れはバッファ + クエリで、小さなファイルが溜まり、毎分クエリ料金がかかる
- 「Glue のバッチジョブを毎分スケジュール」→ 起動の待ちと最低課金があり向かない
- 「EC2 に Kafka を立てる」→ 「インフラの管理を避けたい」に反する。Kafka が要るなら MSK

**問題文のキーワード**
- 「複数のコンシューマー」「再処理 / リプレイ」「順序」「1 秒未満」→ Kinesis Data Streams（+ 拡張ファンアウト）
- 「S3 に配信」「運用を最小」「Parquet に変換」「数分の遅れは許容」→ Firehose
- 「ウィンドウ」「リアルタイムの集計 / 異常検知」「ストリームに SQL」→ Managed Service for Apache Flink

**採らなかった選択肢**
- Lambda のタンブリングウィンドウ（Kinesis をイベントソースにして最大 15 分）: 単純な集計なら答えになりうる。問題文が「ウィンドウ集計」「リアルタイム分析」なら定番は Flink
- Glue のストリーミング ETL: Spark Structured Streaming のマイクロバッチ。S3 への継続的な取り込みと変換には使える
- MSK: 既存の Kafka クライアントやエコシステムを使いたいときに選ぶ

### 15-2. 変換の実行基盤（Glue ETL / Glue DataBrew / EMR / Lambda / Athena の CTAS）

**ペヌスタでの使い方**
- raw → curated の変換は DuckDB（ローカル）で、実 AWS では Athena の CTAS で作り直す案を演習に置いている（dea-exercises 1-1）。Glue の ETL ジョブも EMR も使っていない

**判断ルール**
- 選び方は 2 段: まず必須の要件（既存のジョブをそのまま、コードを書かない、数秒以内など）で消し、残ったものから運用と費用が最小のものを選ぶ
- コードを書かない、表記ゆれや欠損の整形、変換前にプロファイル → Glue DataBrew（レシピ）
- 既存の Spark / Hive のジョブと独自のライブラリ、クラスターの構成を自分で決める、スポットインスタンス、長時間のバッチ → EMR（スポットはタスクノードに使う）
- サーバーレスの Spark で ETL、クラスターを管理したくない → Glue の ETL ジョブ
- 小さなファイルを置かれるたびに数秒以内で処理 → S3 のイベント通知 + Lambda
- S3 上のデータを SQL だけで変換して書き出す → Athena の CTAS / INSERT INTO

**ひっかけ**
- 「サーバーレスの Glue が一番手軽なので Glue」→ 既存の Hive、クラスター構成の制御、スポットの条件があれば EMR。手軽さは 2 段目の決め手で、1 段目の条件で先に消える
- 「ファイルごとに Glue のジョブを起動」→ 起動の待ちと 1 回 1 分の最低課金があり、小さなファイルには割に合わない
- 「常時起動の EMR でファイルを監視」→ 何もしない時間も課金される
- 「5 分ごとのスケジュールで十分」→ 「数秒以内」を満たさない

**問題文のキーワード**
- 「コードを書かない」「アナリスト」「ビジュアル」「プロファイル」→ Glue DataBrew
- 「既存の Hadoop / Spark / Hive」「クラスターを細かく設定」「スポット」→ EMR
- 「サーバーレス」「Spark」「クラスターの管理不要」→ Glue の ETL ジョブ
- 「ファイルが置かれるたび」「数秒以内」「小さい」→ S3 のイベント通知 + Lambda

**採らなかった選択肢**
- Lambda: 実行時間は最大 15 分、メモリは最大 10GB。大きなファイルや重い処理は Glue か EMR。S3 のイベント通知は重複しうるので、書き込みは冪等にする（DynamoDB の PutItem は同じキーなら上書き）
- EMR Serverless: 既存の Spark / Hive をクラスター管理なしで動かしたいときの候補。構成の細かい制御とスポットが要件なら EMR on EC2

### 15-3. 分析のクエリ（Athena / Redshift / Redshift Spectrum / RDS）

**ペヌスタでの使い方**
- 分析は DuckDB（ローカル）と Athena（実 AWS、14.）。Redshift は常時課金のプロビジョンドを避けて使っていない（12.）

**判断ルール**
- 大きな表の結合・集計（TB 級）、多数の同時ユーザー、BI ダッシュボード → Redshift（列指向、MPP）。同時アクセスの急増は Concurrency Scaling
- 同じ形のクエリが繰り返し流れる → Redshift のマテリアライズドビュー（増分の更新、自動の更新、クエリの自動の書き換え）と結果のキャッシュ
- Redshift の表と S3 のデータを 1 本の SQL でまたぐ、S3 のデータは読み込まない → Redshift Spectrum（Glue Data Catalog を外部スキーマとして参照）
- S3 のデータを、たまに・その場で調べる、サーバーなし、クエリした分だけ課金 → Athena
- 行単位の読み書きが中心のアプリ（OLTP） → RDS / Aurora。分析の主役にはしない
- RDS / Aurora か Redshift かは、量でなく処理の形で決める。容量は Aurora 最大 128TiB、RDS for PostgreSQL 最大 64TiB で 10TB は入る。大量の行の集計・結合（OLAP）なら Redshift、行単位の読み書き（OLTP）なら Aurora
- Aurora の業務データを分析に使う、ETL の運用を避けたい → Aurora から Redshift への zero-ETL 統合

**ひっかけ**
- 「マテリアライズドビューがあるので RDS for PostgreSQL でも同じ」→ 1 台で動く行指向で、10TB の結合は 1 本が重い。PostgreSQL のマテリアライズドビューは REFRESH で全部作り直す
- 「10TB なら RDS / Aurora に入るので RDS / Aurora」→ 入るかどうかでなく処理の形。集計・結合が中心なら行指向の 1 台より列指向の MPP。読み取りレプリカは同時に流せる本数を増やすだけで、1 本ごとは速くならない
- 「データマートは RDS」→ データマートは用途ごとの分析用データという意味で、置き場所ではない。TB 級の BI 用なら Redshift / Redshift Serverless
- 「数百人が見るダッシュボードを Athena で」→ クエリごとのスキャン課金と同時実行数のクォータがあり、繰り返しの多い用途に向かない
- 「古いデータも COPY で Redshift に入れる」→ 「読み込みたくない」に反し、保存の費用も増える。Spectrum で外から読む
- 「毎晩まとめ直す / UNLOAD して Athena だけで」→ データの二重化と、毎晩の遅れが生じる
- 「たまの調査に Redshift のプロビジョンド / EMR の Trino」→ 何もしない時間も課金される

**問題文のキーワード**
- 「TB 級」「複雑な結合」「多数の同時ユーザー」「BI」→ Redshift
- 「繰り返しのクエリ」「応答を安定」→ マテリアライズドビュー、結果のキャッシュ
- 「Redshift と S3 をまたぐ」「読み込まずに」→ Redshift Spectrum
- 「アドホック」「たまに」「サーバーレス」「クエリした分だけ」→ Athena

**採らなかった選択肢**
- Redshift Serverless: 使った分だけの課金で、管理も不要。ただ、データの読み込みか Spectrum の設定が要る。S3 をたまに調べるだけなら Athena が最短
- Athena の federated query: RDS や DynamoDB など S3 以外もまたいで SQL を流したいときの選択肢

### 15-4. 移行と連携（DMS / SCT / AppFlow / DataSync / Transfer Family / Snowball）

**判断ルール**
- DB の移行で停止時間を最小に → DMS の全件ロード + CDC（変更データキャプチャ）。停止時間を小さくするのは CDC で、切り替え時は最後の差分が追いつくまでだけ止める
- 異種間の移行（Oracle → PostgreSQL など） → スキーマを SCT（または DMS Schema Conversion）で変換してから DMS。同種間なら SCT は不要
- SaaS（Salesforce、SAP、Slack など）から S3 / Redshift へ、コードなし → Amazon AppFlow
- オンプレミスの NFS / SMB から S3 / EFS / FSx へ、一括 + 定期の差分、整合性の確認 → DataSync（エージェント、タスクのスケジュール、チェックサムでの検証）。AWS のストレージ間やほかのクラウドからの転送にも使う
- 外部の取引先が SFTP / FTPS / FTP で S3 にファイルを置く → Transfer Family
- 回線が細く、オンラインだと数週間かかる → Snowball Edge（オフラインで一度きり）。目安: 10Gbps なら 50TB は理論上約 11 時間（50TB × 8 ÷ 10Gbps）

**ひっかけ**
- 「AppSync で SaaS から取り込む」→ AppSync は GraphQL の API を作るサービス。取り込みは AppFlow（Flow = 流す）
- 「DMS の全件ロードだけで停止時間は最小」→ ロード中の更新は CDC が無いと拾えない
- 「DataSync で SaaS から取り込む」→ DataSync はストレージ間の転送。SaaS の API には AppFlow
- 「`aws s3 sync` の cron で十分」→ s3 sync も差分は送るが、チェックサムの検証、並列化と帯域の制御、スケジュール・再試行・監視を自分で作ることになる
- 「Snowball で差分も」→ 一度きりのオフライン転送。継続の差分は DataSync
- 「Transfer Family で社内のファイルサーバーを移行」→ 外部から SFTP で受け取る入口

**問題文のキーワード**
- 「停止時間を最小」「切り替えまで更新を反映」→ DMS + CDC
- 「異なるデータベースエンジン」→ SCT / DMS Schema Conversion
- 「SaaS」「Salesforce」「コードなし」→ AppFlow
- 「NFS / SMB」「ファイルサーバー」「スケジュール」「整合性の検証」→ DataSync
- 「取引先」「SFTP」→ Transfer Family
- 「帯域が限られる」「数十 TB〜PB」「オフライン」→ Snowball Edge

### 15-5. オーケストレーション（Step Functions / MWAA / EventBridge / Glue Workflows）

**ペヌスタでの使い方**
- 定期実行は Laravel のスケジューラ（13.）。実 AWS の export → sync → 照合のつなぎは Step Functions + EventBridge Scheduler の案を演習に置いている（dea-exercises 1-5）

**判断ルール**
- 複数の AWS サービスをつなぐ、分岐・再試行・失敗時の通知、サーバーレス、図で見える → Step Functions（状態ごとの Retry / Catch。Workflow Studio）
- 既存の Airflow の DAG をそのまま、Airflow の基盤は管理しない → Amazon MWAA
- 決まった時刻に 1 つ起動するだけ → EventBridge Scheduler（タイムゾーン指定、1 回きりのスケジュールも可）
- イベント（S3 にファイルが置かれた等）をきっかけに起動 → EventBridge のルール（S3 のイベント通知を EventBridge に送る）
- Glue のジョブとクローラだけをつなぐ → Glue Workflows（トリガー）

**ひっかけ**
- 「ファイルが来たときだけ流すパイプラインを MWAA で」→ 環境は使っていない時間も 1 時間ごとに課金。Python で DAG を書く。Step Functions は状態遷移の回数だけ課金
- 「Glue Workflows で Athena や SNS もつなぐ」→ Glue のジョブ・クローラ・トリガーしか扱えない
- 「1 日 1 回の起動に Step Functions の Wait で待つループ」→ 余計な仕組み。スケジュールは EventBridge Scheduler
- 「EventBridge のルールの cron で日本時間を指定」→ ルールの cron は UTC。タイムゾーンを指定するなら Scheduler
- 「EC2 の cron」→ サーバーの管理が要る

**問題文のキーワード**
- 「複数のサービス」「分岐」「再試行」「エラー処理」「ビジュアル」「サーバーレス」→ Step Functions
- 「既存の Airflow」「DAG」「書き直さない」→ MWAA
- 「毎日決まった時刻」「タイムゾーン」「1 回だけ」→ EventBridge Scheduler
- 「Glue のジョブとクローラの依存関係」→ Glue Workflows

### 15-6. 権限（IAM / Lake Formation / バケットポリシー / KMS のキーポリシー）

**ペヌスタでの使い方**
- 実 AWS は SSO の DeveloperAccess で触っていて、分析者用の最小権限は未作成（dea-exercises 4-1）。Lake Formation の列・行の制御は演習 4-2 に置いている

**判断ルール**
- Athena / Glue のテーブルで、列だけ隠す・1 か所で管理 → Lake Formation の列単位の権限。行やセルで絞るならデータフィルタ
- テーブルが増え続ける、属性（部門、機密度）で権限を決める → LF-Tags（タグの値に許可。データベースに付けたタグはテーブルに引き継がれる）
- 同じアカウントの中 → IAM ポリシーかバケットポリシーのどちらかで許可していれば読める（明示的な拒否が無い限り）
- アカウントをまたぐ → 呼ぶ側の IAM ポリシーと、バケット側のバケットポリシーの両方で許可が必須
- アカウントをまたいで SSE-KMS のオブジェクトを読む → カスタマー管理キーのキーポリシーと、呼ぶ側の IAM の両方で `kms:Decrypt`

**ひっかけ**
- 「IAM やバケットポリシーで列を隠す」→ S3 の権限の単位はオブジェクト。ファイルの中の列は隠せない
- 「個人情報を除いたコピーを CTAS で作る」→ 二重化と同期の手間。仮名化した別のデータセットを作る場面なら正解になりうる
- 「テーブルごとに権限を付ける」→ 増えるたびに作業が要る。属性で決めるなら LF-Tags
- 「アカウントをまたぐなら IAM だけ / バケットポリシーだけ」→ 両方要る
- 「AWS 管理キー（`aws/s3`）のオブジェクトを別アカウントに読ませる」→ AWS 管理キーのキーポリシーは編集できず、許可を出せない。カスタマー管理キーが要る
- 「バケットを公開する」→ 誰でも読める。ブロックパブリックアクセスにも反する

**問題文のキーワード**
- 「列レベル」「行レベル」「セルレベル」「一元管理」→ Lake Formation（データフィルタ）
- 「タグベース」「属性」「スケール」→ LF-Tags
- 「クロスアカウント」→ IAM ポリシー + リソースポリシー（バケットポリシー、キーポリシー）の両方

**採らなかった選択肢**
- Redshift の列単位の GRANT: データが Redshift の中にあるならこちら

### 15-7. 暗号化と検出（SSE-S3 / SSE-KMS / SSE-C / S3 バケットキー / Macie と似た名前のサービス）

**判断ルール**
- 鍵の使用を監査で追う、鍵を無効にできる、自動ローテーション、管理者と利用者の権限を分ける → SSE-KMS のカスタマー管理キー（KMS の API は CloudTrail に残る。無効化・削除の予約。自動ローテーションは既定 1 年。キーポリシーで権限を分ける）
- 鍵の管理を AWS に任せ、何も設定しない → SSE-S3
- 鍵を自分で持ち、リクエストごとに渡す → SSE-C（AWS は鍵を保存しない。ローテーションも自分で）
- AWS に平文を渡さない → クライアント側の暗号化
- SSE-KMS で KMS のスロットリングや費用が増えた、方式は変えない → S3 バケットキー（バケット単位の鍵で各オブジェクトのデータキーを作り、KMS の呼び出しを大きく減らす。暗号化は SSE-KMS のまま）
- S3 の中の個人情報・機密データを見つける → Macie

**ひっかけ**
- 「S3 バケットキーは暗号化の方式の変更」→ SSE-KMS のまま。変わるのは KMS への問い合わせの回数（2026-11-01 の演習で、方式の変更と見て外した）
- 「KMS のスロットリングにアプリの同時実行を増やす」→ リクエストが増えて悪化する。消去法で残った選択肢は、要件を満たすかを最後に確かめる
- 「マルチリージョンキーでスロットリングが減る」→ 別のリージョンで同じ鍵を使うためのもの。リクエスト数は変わらない
- 「SSE-C で鍵の監査・無効化・自動ローテーション」→ AWS は鍵を保存しないので、どれもできない
- 「Inspector で侵害を調査」→ Inspector は脆弱性のスキャン（EC2、ECR、Lambda）。侵害の調査は Detective

**似た名前のサービス**
- Macie: S3 の機密データの検出
- GuardDuty: 脅威の検知（怪しい API の呼び出し、悪意のある IP との通信）
- Inspector: 脆弱性のスキャン
- Detective: 侵害の調査（GuardDuty の検知から追う）
- Config: リソースの設定の履歴と、ルールへの準拠の評価

**問題文のキーワード**
- 「鍵の使用を監査」「鍵を無効化」「ローテーション」「職務の分離」→ SSE-KMS のカスタマー管理キー
- 「KMS のスロットリング」「KMS の費用」「大量のオブジェクト」→ S3 バケットキー
- 「個人情報」「機密データの検出」「S3」→ Macie

### 15-8. 運用と監視（CloudTrail / Glue Data Quality / EventBridge / CloudWatch）

**ペヌスタでの使い方**
- データ品質の検査は、Athena の SQL で書く案を演習に置いている（dea-exercises 3-1）。Glue Data Quality はその対比。CloudTrail のイベント履歴で自分の操作を探す演習も 3-4 にある

**判断ルール**
- 誰が・いつ・どこからリソースを消した / 設定を変えた → CloudTrail の管理イベント（既定で記録。イベント履歴で直近 90 日を無料で見られる）
- 誰が S3 のオブジェクトを読んだ / 書いた、Lambda の実行 → CloudTrail のデータイベント（既定では記録されない。証跡を作って有効にする）
- データの値の品質（欠損、行数、一意性、値の範囲）を管理された規則で検査、合わなければ止める → Glue Data Quality（DQDL の規則。カタログのテーブルに対して評価するか、ETL ジョブの中で EvaluateDataQuality。失敗時はジョブを止めるか、合わない行を分ける。規則の推薦あり）
- サービスの状態の変化（Glue のジョブの失敗など）をきっかけに通知・後続を起動 → EventBridge のルール（Job State Change の FAILED → SNS / SQS / Lambda / Step Functions）
- メトリクスがしきい値を超えたら通知 → CloudWatch のアラーム

**ひっかけ**
- 「CloudTrail はすべての API を既定で記録」→ 既定は管理イベントだけ。S3 のオブジェクトの読み書きはデータイベントで、証跡で有効にしないと残らない
- 「CloudWatch のメトリクスで誰が消したか分かる」→ 回数は分かっても、誰かは分からない
- 「品質の検査を PySpark の assert / Athena + Lambda で」→ 動くが自前の仕組み。「管理された仕組みで」なら Glue Data Quality
- 「Macie でデータ品質」→ Macie は機密データの検出
- 「Lambda で 1 分ごとにジョブの状態を見る」→ 遅れと無駄な実行。状態の変化はイベントで受ける

**問題文のキーワード**
- 「誰が」「いつ」「API の呼び出し」「監査」→ CloudTrail（オブジェクトの読み書きならデータイベント）
- 「データ品質」「規則」「欠損」「完全性」「DQDL」→ Glue Data Quality
- 「失敗したらすぐ通知」「状態の変化」→ EventBridge のルール + SNS

### 15-9. Redshift の設計（分散キー / ソートキー / COPY / VACUUM）

**判断ルール**
- 大きな表どうしを同じ列でよく結合する → 両方の表でその列を分散キー（KEY 分散）。同じ値が同じスライスに来るので、結合でノード間の移動が要らない
- 小さくてあまり変わらない次元の表（マスタ） → ALL 分散（全ノードにコピー）
- 結合に使わない、決まったキーが無い → EVEN 分散
- 期間などの範囲で絞り込む列 → ソートキー。ゾーンマップ（ブロックごとの最小値・最大値）で範囲外のブロックを飛ばす。条件の列が 1 つならシンプルなソートキーで足りる
- S3 から大量に読み込む → COPY。圧縮した複数のファイルに分け、ファイル数をスライス数の倍数にして並列に読む。大きさは揃える（圧縮後 1MB〜1GB が目安）。読むファイルを明示するならマニフェスト
- 削除・更新の後の領域の回収と並び順の整え直し → VACUUM（ソートキーの効き目を保つ保守）。統計の更新は ANALYZE

**ひっかけ**
- 「数千万行の顧客の表も ALL 分散」→ 保存の量がノード数の倍になり、読み込み・更新も全ノード。ALL は小さいマスタ向け。表の行数を必ず拾う（2026-11-01 の演習で、選択肢にある ALL を見落とした）
- 「日付を分散キーにして期間検索を速く」→ 直近の日付が特定のノードに偏る。期間の絞り込みはソートキー
- 「片方だけ分散キー」→ もう片方を結合のたびに移すことになる
- 「1 つの大きなファイルを COPY」→ 1 スライスしか働かない
- 「1 行ずつ INSERT」→ 最も遅い。大量の読み込みは COPY
- 「VACUUM でスキャン量が減る」→ ソートキーが無ければ範囲外のブロックは飛ばせない

**問題文のキーワード**
- 「結合」「データの再分散を最小」→ 結合の列を分散キー
- 「小さい次元の表」→ ALL 分散
- 「範囲で絞り込む」「日付」→ ソートキー
- 「大量の読み込み」「最速」→ COPY + 分割・圧縮したファイル
