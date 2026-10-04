-- VACUUM の保持期間を 60 秒にする（学習用。既定は 5 日）。メタデータの更新のみ。
-- 注意: この後の 08_vacuum.sql で 60 秒より古いスナップショットが消え、05 のタイムトラベルは効かなくなる。05 を先に済ませておく。
ALTER TABLE events_iceberg SET TBLPROPERTIES ('vacuum_max_snapshot_age_seconds' = '60')
