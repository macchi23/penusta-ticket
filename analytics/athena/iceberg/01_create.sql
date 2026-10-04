-- curated（Parquet）から Iceberg テーブルを CTAS で作る。curated 全体（約 20 万行）をスキャンし、S3 の iceberg/events/ にファイルを書く。
-- Hive テーブルの timestamp は Athena v3 で精度 3 固定だが、Iceberg の CTAS / 書き込みは timestamp(6) が必要なので CAST する（データにミリ秒未満は無く値は変わらない）。day() は 1 クエリ 100 パーティション上限に当たるので month。
CREATE TABLE events_iceberg
WITH (
  table_type = 'ICEBERG',
  is_external = false,
  location = 's3://{{bucket}}/iceberg/events/',
  format = 'PARQUET',
  partitioning = ARRAY['month(occurred_at)']
) AS
SELECT id, event_type, user_id, session_id, device, payload,
  CAST(occurred_at AS timestamp(6)) AS occurred_at, CAST(created_at AS timestamp(6)) AS created_at, match_id, jst_date
FROM events_curated
