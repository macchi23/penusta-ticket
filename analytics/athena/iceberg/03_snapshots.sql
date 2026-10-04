-- スナップショット履歴の確認（メタデータのみ。スキャンはほぼ 0）。01 の後は append、04 の MERGE の後は overwrite 等が増える。
SELECT committed_at, snapshot_id, operation FROM "events_iceberg$snapshots" ORDER BY committed_at
