# DEA 学習メモ（ペヌスタでの実装と対応づけ）

AWS Certified Data Engineer – Associate（DEA-C01）の論点を、このリポジトリで実際に下した判断と結びつけて記録する。
タスクを終えるたびに追記する。料金・上限値は記憶ベースのものに「要確認」を付けている。

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
- Athena CTAS / INSERT INTO は 1 回のクエリで書けるパーティション数に上限がある（100、要確認）→ 多数の日付をまとめて変換するときは分割して実行

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
- 1 dt = 1 ファイル。1 日分が gzip で約 400KB なので分割しない
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
- ロードマップ: 実 AWS で curated を Iceberg テーブル化する（Glue Data Catalog + Athena）

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

**問題文のキーワード**
- 「レコード単位の更新・削除」「ACID」「タイムトラベル」「ユーザーのデータを削除する要求」→ Iceberg（Athena / Glue）
- 「運用負荷を最小に Iceberg を使いたい」→ S3 Tables（要確認）

**採らなかった選択肢**
- ローカルで Iceberg（REST カタログ + DuckDB、または PyIceberg + SQLite カタログ）: 仕組みを手元で確かめられるが今回の範囲外

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
- 従量で安い: S3、Athena（スキャン 1TB あたり約 $5、1 クエリ最低 10MB）、Glue Data Catalog（100 万オブジェクトまで無料）、Glue ETL（DPU 時間課金）、Firehose（取り込み GB 課金）、Lambda、Step Functions、EventBridge Scheduler
- 時間課金で放置すると高い: Kinesis Data Streams（シャード時間）、Redshift Serverless（RPU 時間、使った時だけ）、MWAA（環境の時間課金、最小構成でも月数百ドル）、EMR（インスタンス時間）

**判断ルール**
- Athena のコストを下げたい → パーティション、列指向、圧縮（スキャン量を減らす）
- 使うときだけ課金されたい → サーバーレス（Athena、Glue、Redshift Serverless、EMR Serverless）
- アクセス頻度の低い古いデータ → S3 ライフサイクルで Standard-IA / Glacier へ。アクセスパターンが不明 → Intelligent-Tiering

**ひっかけ**
- 「Athena の料金は実行時間で決まる」→ スキャン量で決まる（プロビジョンド容量を使う場合を除く）
- 「月に数回のクエリのために Redshift プロビジョンドクラスタを常時起動」→ Athena か Redshift Serverless

**問題文のキーワード**
- 「最もコスト効率が良い」→ サーバーレス、スキャン量の削減、ライフサイクル
- 「アクセスパターンが予測できない」→ S3 Intelligent-Tiering
