-- events を curated へ Parquet 出力する。payload は Athena / Glue 互換のため JSON 型ではなく VARCHAR で書く。
COPY (SELECT * REPLACE (CAST(payload AS VARCHAR) AS payload) FROM events)
  TO 's3://penusta-events/curated/events'
  (FORMAT parquet, PARTITION_BY (dt), OVERWRITE_OR_IGNORE, FILENAME_PATTERN 'part-{i}');
