-- raw (JSON Lines gz) を読んで events テーブル / events_clean ビューを作る（curated 出力は export_curated.sql）。
-- 型推論は使わず columns 明示（稀なキーで型がぶれるため）。
CREATE OR REPLACE TABLE events AS
SELECT *, CAST(payload->>'match_id' AS BIGINT) AS match_id,
       CAST(timezone('Asia/Tokyo', occurred_at) AS DATE) AS jst_date
FROM read_json('s3://penusta-events/raw/events/dt=*/*.jsonl.gz', format='newline_delimited',
  hive_partitioning=true,
  columns={id:'BIGINT', event_type:'VARCHAR', user_id:'BIGINT', session_id:'VARCHAR',
           device:'VARCHAR', payload:'JSON', occurred_at:'TIMESTAMPTZ', created_at:'TIMESTAMPTZ'});
CREATE OR REPLACE VIEW events_clean AS
  FROM events WHERE session_id NOT LIKE 'e2e00000-0000-4000-8000-%' AND session_id <> 'unknown';
