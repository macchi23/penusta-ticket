-- 小さなデータファイルを BIN_PACK で結合する（MERGE 後の断片化の解消）。対象ファイルを読み書きするのでスキャン課金と S3 への書き込みが発生する。
-- 古いファイルは残る（スナップショットから参照されるため）。消すのは 08_vacuum.sql。
-- 注意: delete file が optimize_rewrite_delete_file_threshold（既定 2）未満のデータファイルは書き換えないので、MERGE 1 回後は no-op になりうる。
OPTIMIZE events_iceberg REWRITE DATA USING BIN_PACK
