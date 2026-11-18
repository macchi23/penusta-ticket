-- events を curated へ Parquet 出力する。payload は Athena / Glue 互換のため JSON 型ではなく VARCHAR で書く。
-- PARTITION_BY の writer はスレッド間で共有され、開いている数が partitioned_write_max_open_files（既定 100）に達すると 1 本閉じられる。
-- 閉じた dt に後から行が来ると part-1 が開かれ、1 dt が割れる（並列だと dt をまたいで行が来るため。events は dt 順なので threads=1 だと起きない）。
-- dt の数（1,853）より大きい上限にして、閉じさせない。
SET partitioned_write_max_open_files = 10000;
COPY (SELECT * REPLACE (CAST(payload AS VARCHAR) AS payload) FROM events)
  TO 's3://penusta-events/curated/events'
  (FORMAT parquet, PARTITION_BY (dt), OVERWRITE_OR_IGNORE, FILENAME_PATTERN 'part-{i}');
RESET partitioned_write_max_open_files;

-- 業務テーブル（パーティションなし。json 列は VARCHAR）。
COPY seasons TO 's3://penusta-events/curated/seasons/part-0.parquet' (FORMAT parquet);
COPY teams TO 's3://penusta-events/curated/teams/part-0.parquet' (FORMAT parquet);
COPY (SELECT * REPLACE (CAST(attributes AS VARCHAR) AS attributes) FROM matches)
  TO 's3://penusta-events/curated/matches/part-0.parquet' (FORMAT parquet);
COPY league_standings TO 's3://penusta-events/curated/league_standings/part-0.parquet' (FORMAT parquet);
COPY league_matches TO 's3://penusta-events/curated/league_matches/part-0.parquet' (FORMAT parquet);
COPY stands TO 's3://penusta-events/curated/stands/part-0.parquet' (FORMAT parquet);
COPY seat_categories TO 's3://penusta-events/curated/seat_categories/part-0.parquet' (FORMAT parquet);
COPY blocks TO 's3://penusta-events/curated/blocks/part-0.parquet' (FORMAT parquet);
COPY match_block_allocations TO 's3://penusta-events/curated/match_block_allocations/part-0.parquet' (FORMAT parquet);
COPY ticket_types TO 's3://penusta-events/curated/ticket_types/part-0.parquet' (FORMAT parquet);
COPY match_prices TO 's3://penusta-events/curated/match_prices/part-0.parquet' (FORMAT parquet);
COPY sale_phases TO 's3://penusta-events/curated/sale_phases/part-0.parquet' (FORMAT parquet);
COPY orders TO 's3://penusta-events/curated/orders/part-0.parquet' (FORMAT parquet);
COPY order_items TO 's3://penusta-events/curated/order_items/part-0.parquet' (FORMAT parquet);
COPY tickets TO 's3://penusta-events/curated/tickets/part-0.parquet' (FORMAT parquet);
