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

-- 業務テーブル（tables:export の全量スナップショット。raw/<表>/part-0000.jsonl.gz）。events と同じく columns 明示。
-- 時刻は UTC の Zulu 文字列で書き出される。
CREATE OR REPLACE TABLE seasons AS
SELECT * FROM read_json('s3://penusta-events/raw/seasons/*.jsonl.gz', format='newline_delimited',
  columns={id:'BIGINT', name:'VARCHAR', year:'INTEGER', format:'VARCHAR', starts_on:'DATE', ends_on:'DATE'});
CREATE OR REPLACE TABLE teams AS
SELECT * FROM read_json('s3://penusta-events/raw/teams/*.jsonl.gz', format='newline_delimited',
  columns={id:'BIGINT', name:'VARCHAR', short_name:'VARCHAR', color:'VARCHAR', is_home_club:'BOOLEAN'});
-- attributes は型付きの列に展開し、元の JSON も残す。unsold は無い試合を false にする（calibration_pg.sql と同じ扱い）。
CREATE OR REPLACE TABLE matches AS
SELECT *,
       attributes->>'weather' AS weather,
       CAST(attributes->>'temperature' AS DOUBLE) AS temperature,
       attributes->>'day_type' AS day_type,
       CAST(attributes->>'is_holiday' AS BOOLEAN) AS is_holiday,
       CAST(attributes->>'opponent_popularity' AS INTEGER) AS opponent_popularity,
       CAST(attributes->>'promo' AS BOOLEAN) AS promo,
       CAST(attributes->>'round' AS INTEGER) AS round,
       CAST(attributes->>'round_ratio' AS DOUBLE) AS round_ratio,
       coalesce(CAST(attributes->>'unsold' AS BOOLEAN), false) AS unsold
FROM read_json('s3://penusta-events/raw/matches/*.jsonl.gz', format='newline_delimited',
  columns={id:'BIGINT', season_id:'BIGINT', home_team_id:'BIGINT', away_team_id:'BIGINT', stadium_id:'BIGINT',
           kickoff_at:'TIMESTAMPTZ', status:'VARCHAR', section_label:'VARCHAR', attributes:'JSON'});
CREATE OR REPLACE TABLE league_standings AS
SELECT * FROM read_json('s3://penusta-events/raw/league_standings/*.jsonl.gz', format='newline_delimited',
  columns={id:'BIGINT', season_id:'BIGINT', team_id:'BIGINT', round:'INTEGER', position:'INTEGER', played:'INTEGER',
           won:'INTEGER', drawn:'INTEGER', lost:'INTEGER', goals_for:'INTEGER', goals_against:'INTEGER',
           points:'INTEGER', form:'VARCHAR'});
CREATE OR REPLACE TABLE league_matches AS
SELECT * FROM read_json('s3://penusta-events/raw/league_matches/*.jsonl.gz', format='newline_delimited',
  columns={id:'BIGINT', season_id:'BIGINT', home_team_id:'BIGINT', away_team_id:'BIGINT', match_id:'BIGINT',
           round:'INTEGER', kickoff_at:'TIMESTAMPTZ', home_score:'INTEGER', away_score:'INTEGER'});
CREATE OR REPLACE TABLE stands AS
SELECT * FROM read_json('s3://penusta-events/raw/stands/*.jsonl.gz', format='newline_delimited',
  columns={id:'BIGINT', stadium_id:'BIGINT', name:'VARCHAR', code:'VARCHAR'});
CREATE OR REPLACE TABLE seat_categories AS
SELECT * FROM read_json('s3://penusta-events/raw/seat_categories/*.jsonl.gz', format='newline_delimited',
  columns={id:'BIGINT', stadium_id:'BIGINT', name:'VARCHAR', display_order:'INTEGER'});
CREATE OR REPLACE TABLE blocks AS
SELECT * FROM read_json('s3://penusta-events/raw/blocks/*.jsonl.gz', format='newline_delimited',
  columns={id:'BIGINT', stand_id:'BIGINT', seat_category_id:'BIGINT', code:'VARCHAR', seating_type:'VARCHAR', capacity:'INTEGER'});
CREATE OR REPLACE TABLE match_block_allocations AS
SELECT * FROM read_json('s3://penusta-events/raw/match_block_allocations/*.jsonl.gz', format='newline_delimited',
  columns={id:'BIGINT', match_id:'BIGINT', block_id:'BIGINT', allocation:'VARCHAR'});
CREATE OR REPLACE TABLE ticket_types AS
SELECT * FROM read_json('s3://penusta-events/raw/ticket_types/*.jsonl.gz', format='newline_delimited',
  columns={id:'BIGINT', name:'VARCHAR', code:'VARCHAR', display_order:'INTEGER'});
CREATE OR REPLACE TABLE match_prices AS
SELECT * FROM read_json('s3://penusta-events/raw/match_prices/*.jsonl.gz', format='newline_delimited',
  columns={id:'BIGINT', match_id:'BIGINT', seat_category_id:'BIGINT', ticket_type_id:'BIGINT', price:'INTEGER'});
CREATE OR REPLACE TABLE sale_phases AS
SELECT * FROM read_json('s3://penusta-events/raw/sale_phases/*.jsonl.gz', format='newline_delimited',
  columns={id:'BIGINT', match_id:'BIGINT', kind:'VARCHAR', method:'VARCHAR', eligibility:'VARCHAR',
           starts_at:'TIMESTAMPTZ', ends_at:'TIMESTAMPTZ'});
CREATE OR REPLACE TABLE orders AS
SELECT * FROM read_json('s3://penusta-events/raw/orders/*.jsonl.gz', format='newline_delimited',
  columns={id:'BIGINT', user_id:'BIGINT', match_id:'BIGINT', status:'VARCHAR', total:'INTEGER', channel:'VARCHAR',
           ordered_at:'TIMESTAMPTZ', expires_at:'TIMESTAMPTZ', sale_phase_id:'BIGINT', kind:'VARCHAR',
           created_at:'TIMESTAMPTZ', updated_at:'TIMESTAMPTZ'});
CREATE OR REPLACE TABLE order_items AS
SELECT * FROM read_json('s3://penusta-events/raw/order_items/*.jsonl.gz', format='newline_delimited',
  columns={id:'BIGINT', order_id:'BIGINT', ticket_id:'BIGINT', ticket_type_id:'BIGINT', price:'INTEGER'});
CREATE OR REPLACE TABLE tickets AS
SELECT * FROM read_json('s3://penusta-events/raw/tickets/*.jsonl.gz', format='newline_delimited',
  columns={id:'BIGINT', match_id:'BIGINT', block_id:'BIGINT', seat_id:'BIGINT', status:'VARCHAR', expires_at:'TIMESTAMPTZ',
           season_pass_seat_id:'BIGINT', created_at:'TIMESTAMPTZ', updated_at:'TIMESTAMPTZ'});
