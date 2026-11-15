-- シーズン x 席種の 1 試合あたり販売枚数（一般販売 = orders.kind = 'purchase'。年パス発行 season_pass・リセール resale は除く）。
-- 「売れた」の数え方は calibration_pg.sql に合わせ、tickets.status / orders.status では絞らない（リセール済みの元チケットも一次では売れた扱い）。
-- 分母 = AS_OF（2026-10-08 JST）までにキックオフした、未販売でない試合の数（calibration_pg.sql の cal_match と同じ）。
-- 定義の違い: calibration_pg.sql の cal_block は試合ごとの割当が home / away のブロックだけを数え、away を席種とは別に分けるが、
-- このクエリは割当で絞らず、away に割り当てたブロックの販売も席種に含める（1-5 の記事の数字はこの定義）。
WITH m AS (
  SELECT id, season_id FROM matches
  WHERE NOT unsold AND kickoff_at < TIMESTAMPTZ '2026-10-08 00:00:00+09'
), n AS (
  SELECT season_id, count(*) AS matches FROM m GROUP BY season_id
), sold AS (
  SELECT m.season_id, sc.name AS seat_category, count(*) AS sold
  FROM m
  JOIN orders o ON o.match_id = m.id AND o.kind = 'purchase'
  JOIN order_items i ON i.order_id = o.id
  JOIN tickets t ON t.id = i.ticket_id
  JOIN blocks b ON b.id = t.block_id
  JOIN seat_categories sc ON sc.id = b.seat_category_id
  GROUP BY 1, 2
)
SELECT s.name AS season, sold.seat_category, n.matches, sold.sold,
       round(sold.sold / n.matches, 1) AS sold_per_match
FROM sold JOIN n USING (season_id) JOIN seasons s ON s.id = sold.season_id
ORDER BY s.year, s.starts_on, sold.seat_category;
