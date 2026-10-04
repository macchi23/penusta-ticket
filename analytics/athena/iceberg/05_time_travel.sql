-- タイムトラベル。MERGE 前（最初のスナップショット）と現在の件数を並べる。件数は同じだが、過去のスナップショットを読めること自体の確認。
-- FOR VERSION AS OF は式を取れないので、03_snapshots.sql の最初の行（01 の CTAS）の snapshot_id を下の 0 と置き換えてから実行する。
-- FOR TIMESTAMP AS OF は実行時刻に依存するため使わない。メタデータ + 全列を使わない count なのでスキャンは小さい。
SELECT 'before_merge' AS point, count(*) AS row_count FROM events_iceberg FOR VERSION AS OF 0
UNION ALL
SELECT 'current' AS point, count(*) AS row_count FROM events_iceberg
