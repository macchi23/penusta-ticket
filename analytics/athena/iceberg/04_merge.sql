-- 遅延到着データの冪等な取り込みを想定した MERGE INTO。curated の 1 日分を id でマージする。データは同一なので funnel は変わらない（冪等性の確認）。
-- curated の 1 日分と Iceberg 全体をスキャンし、該当月のデータファイルを書き換えて新しいスナップショットを作る。
MERGE INTO events_iceberg t
USING (
  SELECT id, event_type, user_id, session_id, device, payload,
    CAST(occurred_at AS timestamp(6)) AS occurred_at, CAST(created_at AS timestamp(6)) AS created_at, match_id, jst_date
  FROM events_curated
  WHERE dt = '2026-01-10'
) s
ON t.id = s.id
WHEN MATCHED THEN UPDATE SET
  event_type = s.event_type, user_id = s.user_id, session_id = s.session_id, device = s.device, payload = s.payload,
  occurred_at = s.occurred_at, created_at = s.created_at, match_id = s.match_id, jst_date = s.jst_date
WHEN NOT MATCHED THEN INSERT (id, event_type, user_id, session_id, device, payload, occurred_at, created_at, match_id, jst_date)
  VALUES (s.id, s.event_type, s.user_id, s.session_id, s.device, s.payload, s.occurred_at, s.created_at, s.match_id, s.jst_date)
