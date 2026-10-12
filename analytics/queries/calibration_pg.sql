-- 需要モデルの調整用集計（PostgreSQL）。合成データの生成直後に、席種・シーズンごとの販売率と完売を確認する。
-- 前提: scale=1 で生成したテスト DB（penusta_test）。scale が小さいと販売可能席数の分母が合わない。
--   docker compose exec -e DB_DATABASE=penusta_test -e USE_ZEND_ALLOC=0 app php artisan penusta:generate-demo-data --seasons=7 --scale=1
-- 実行:
--   docker compose exec -T db psql -U penusta -d penusta_test -f - < analytics/queries/calibration_pg.sql
-- 分母（販売可能席数）= その試合で home に割り当てたブロックの capacity − 年パスの固定席。固定席の数は SeasonPassSeating::split と同じ式
-- （ゴール裏 65% / バック 25% / 残りメイン）で、シーズンの年パス会員数から出す。
-- 販売率は一般販売の注文（orders.kind = 'purchase'。年パスの発行分は kind = 'season_pass'、リセールの買い手は kind = 'resale' で除く）の枚数 / 販売可能席数。
-- リセールで売れた元のチケットは status = 'resold' になるが、注文（purchase）は残るので「一次で売れた」枚数に数え続ける（tickets.status では絞らない）。
-- 完売ブロックは、固定席の数をブロックごとには持てないので、固定席を同じ席種の「常に home のブロック」で均等割りした
-- 見込みの販売可能席数の :sold_out_ratio 以上を売ったものとする（近似）。
-- 対象は AS_OF（2026-10-08 JST）までにキックオフした、未販売でない試合。

\set sold_out_ratio 0.95
\set late_from 0.66

CREATE TEMP VIEW cal_match AS
SELECT m.id AS match_id, s.id AS season_id, s.name AS season, m.kickoff_at,
       (m.attributes->>'round_ratio')::numeric AS round_ratio,
       coalesce((m.attributes->>'promo')::boolean, false) AS promo,
       (m.attributes->>'opponent_popularity')::int AS popularity
FROM matches m JOIN seasons s ON s.id = m.season_id
WHERE coalesce((m.attributes->>'unsold')::boolean, false) = false
  AND m.kickoff_at < timestamptz '2026-10-08 00:00:00+09';

-- シーズンの年パス会員数と、席種ごとの固定席数
CREATE TEMP VIEW cal_pass AS
SELECT s.id AS season_id, h.holders,
       round(h.holders * 0.65)::int AS fixed_goal_back,
       round(h.holders * 0.25)::int AS fixed_back,
       h.holders - round(h.holders * 0.65)::int - round(h.holders * 0.25)::int AS fixed_main
FROM seasons s
CROSS JOIN LATERAL (
  SELECT count(*)::int AS holders FROM memberships ms
  WHERE ms.kind = 'season_pass' AND ms.valid_until = s.ends_on
) h;

CREATE TEMP VIEW cal_type AS
SELECT b.id AS block_id, b.capacity,
       CASE sc.name WHEN 'ゴール裏' THEN 'goal_back' WHEN 'カテゴリー3' THEN 'back' ELSE 'main' END AS seat_type,
       (SELECT count(*) = 0 FROM match_block_allocations a WHERE a.block_id = b.id AND a.allocation <> 'home') AS always_home
FROM blocks b JOIN seat_categories sc ON sc.id = b.seat_category_id;

-- 試合 × ブロック: 割当・一般販売の枚数・見込みの販売可能席数
CREATE TEMP TABLE cal_block AS
SELECT cm.match_id, cm.season, cm.round_ratio, cm.promo, cm.popularity, t.block_id, t.seat_type, a.allocation, t.capacity,
       coalesce(g.sold, 0) AS sold,
       t.capacity - CASE WHEN a.allocation = 'home' AND t.always_home THEN
         (CASE t.seat_type WHEN 'goal_back' THEN p.fixed_goal_back WHEN 'back' THEN p.fixed_back ELSE p.fixed_main END)::numeric
         / (SELECT count(*) FROM cal_type t2 WHERE t2.seat_type = t.seat_type AND t2.always_home)
       ELSE 0 END AS sellable
FROM cal_match cm
JOIN match_block_allocations a ON a.match_id = cm.match_id AND a.allocation IN ('home', 'away')
JOIN cal_type t ON t.block_id = a.block_id
JOIN cal_pass p ON p.season_id = cm.season_id
LEFT JOIN (
  SELECT tk.match_id, tk.block_id, count(*) AS sold
  FROM tickets tk JOIN order_items i ON i.ticket_id = tk.id JOIN orders o ON o.id = i.order_id
  WHERE o.kind = 'purchase'
  GROUP BY tk.match_id, tk.block_id
) g ON g.match_id = cm.match_id AND g.block_id = t.block_id;

ANALYZE cal_block;

\echo '== 1. シーズン x 席種の販売率（試合平均。away = アウェー割当のブロック）'
SELECT season,
       round(avg(rate) FILTER (WHERE grp = 'goal_back'), 3) AS goal_back,
       round(avg(rate) FILTER (WHERE grp = 'main'), 3) AS main,
       round(avg(rate) FILTER (WHERE grp = 'back'), 3) AS back,
       round(avg(rate) FILTER (WHERE grp = 'away'), 3) AS away,
       round(avg(rate) FILTER (WHERE grp <> 'away'), 3) AS home_all,
       count(DISTINCT match_id) AS matches
FROM (
  SELECT season, match_id, CASE WHEN allocation = 'away' THEN 'away' ELSE seat_type END AS grp,
         sum(sold) / nullif(sum(sellable), 0) AS rate
  FROM cal_block GROUP BY season, match_id, 3
) x
GROUP BY season ORDER BY season;

\echo '== 2. 完売ブロックの割合（home のブロック）。late = round_ratio >= late_from。all_sold_out_matches = home の全ブロックが完売した試合数'
SELECT season,
       round(avg((sold >= :sold_out_ratio * sellable)::int), 3) AS sold_out_share,
       round(avg((sold >= :sold_out_ratio * sellable)::int) FILTER (WHERE round_ratio >= :late_from), 3) AS sold_out_share_late,
       round(avg((sold >= :sold_out_ratio * sellable)::int) FILTER (WHERE round_ratio < :late_from), 3) AS sold_out_share_early,
       (SELECT count(*) FROM (
          SELECT match_id FROM cal_block c2 WHERE c2.season = c.season AND c2.allocation = 'home'
          GROUP BY match_id HAVING bool_and(sold >= :sold_out_ratio * sellable)
        ) f) AS all_sold_out_matches,
       count(DISTINCT match_id) AS matches
FROM cal_block c WHERE allocation = 'home'
GROUP BY season ORDER BY season;

\echo '== 3. 前半と終盤（round_ratio >= late_from）の販売率（home の全席種、試合平均）'
SELECT season,
       round(avg(rate) FILTER (WHERE round_ratio < :late_from), 3) AS early,
       round(avg(rate) FILTER (WHERE round_ratio >= :late_from), 3) AS late
FROM (
  SELECT season, match_id, round_ratio, sum(sold) / nullif(sum(sellable), 0) AS rate
  FROM cal_block WHERE allocation = 'home' GROUP BY season, match_id, round_ratio
) x
GROUP BY season ORDER BY season;

\echo '== 4. promo と非 promo の販売率（home の全席種、試合平均）。non_promo_same_pop = promo の試合と相手の人気度が同じ非 promo の試合の平均'
SELECT season,
       round(avg(rate) FILTER (WHERE promo), 3) AS promo,
       round(avg(rate) FILTER (WHERE NOT promo), 3) AS non_promo,
       round(avg(rate) FILTER (WHERE NOT promo AND popularity IN (SELECT popularity FROM cal_match p WHERE p.promo AND p.season = x.season)), 3) AS non_promo_same_pop,
       count(*) FILTER (WHERE promo) AS promo_matches
FROM (
  SELECT season, match_id, promo, popularity, sum(sold) / nullif(sum(sellable), 0) AS rate
  FROM cal_block WHERE allocation = 'home' GROUP BY season, match_id, promo, popularity
) x
GROUP BY season ORDER BY season;

SELECT 'all seasons' AS season,
       round(avg(rate) FILTER (WHERE promo), 3) AS promo,
       round(avg(rate) FILTER (WHERE NOT promo), 3) AS non_promo
FROM (
  SELECT match_id, promo, sum(sold) / nullif(sum(sellable), 0) AS rate
  FROM cal_block WHERE allocation = 'home' GROUP BY match_id, promo
) x;

\echo '== 5. 年パスの発行数の検証: 発行数 = 年パス会員数 x そのシーズンの試合数（未販売の試合・AS_OF 以降の試合も含む全試合）。diff = 0 が正'
SELECT s.name AS season, p.holders, count(*) AS matches, p.holders * count(*) AS expected,
       sum(coalesce(r.issued, 0)) AS issued,
       sum(coalesce(r.issued, 0)) - p.holders * count(*) AS diff
FROM seasons s
JOIN cal_pass p ON p.season_id = s.id
JOIN matches m ON m.season_id = s.id
LEFT JOIN (
  SELECT tk.match_id, count(*) AS issued
  FROM tickets tk JOIN order_items i ON i.ticket_id = tk.id JOIN orders o ON o.id = i.order_id
  WHERE o.kind = 'season_pass'
  GROUP BY tk.match_id
) r ON r.match_id = m.id
WHERE EXISTS (SELECT 1 FROM season_pass_seats q WHERE q.season_id = s.id)
GROUP BY s.name, p.holders ORDER BY s.name;

-- ここから リセール（6〜8）。対象は cal_match の試合。需要帯は、その試合の home の一次販売率（上の cal_block。resold の元チケットを含む）で切る:
--   1_low < 0.55 <= 2_mid < 0.70 <= 3_high < 0.85 <= 4_sold_out。出品元は元のチケットの注文の種別（season_pass = 年パス券 / purchase = 通常購入券）。
CREATE TEMP VIEW cal_band AS
SELECT match_id,
       CASE WHEN rate < 0.55 THEN '1_low' WHEN rate < 0.70 THEN '2_mid' WHEN rate < 0.85 THEN '3_high' ELSE '4_sold_out' END AS band
FROM (SELECT match_id, sum(sold) / nullif(sum(sellable), 0) AS rate FROM cal_block WHERE allocation = 'home' GROUP BY match_id) x;

-- 一次販売（kind = 'purchase'）の注文ごとの枚数。リセールの前後の一次の残席を数えるのに使う（resold の元チケットも数える）
CREATE TEMP TABLE cal_pcount AS
SELECT o.match_id, tk.block_id, o.ordered_at, count(*) AS n
FROM orders o JOIN order_items i ON i.order_id = o.id JOIN tickets tk ON tk.id = i.ticket_id
WHERE o.kind = 'purchase'
GROUP BY o.id, o.match_id, tk.block_id, o.ordered_at;
CREATE INDEX ON cal_pcount (match_id, block_id, ordered_at);
ANALYZE cal_pcount;

-- 出品 1 件ごと。listed_at / sold_at は timestamptz にそろえる（resale_listings.created_at は UTC の timestamp）。
-- 値下げの再出品は、取り下げ（cancelled）の行と、同じチケットの新しい行になる。出品率・成約率はチケット単位（is_final = 取り下げでない行）で見る。
-- first_listed_at = そのチケットの最初の出品時刻。discounted = 支払額が一次の同席種・同券種の価格より安い。
-- block_full = そのブロックが最終的に完売（cal_block.sold が sold_out_ratio × sellable 以上）
CREATE TEMP TABLE cal_listing AS
SELECT l.id, l.ticket_id, l.match_id, l.block_id, l.ticket_type_id, t.seat_type, bl.seating_type, b.band, o.kind AS source, l.status,
       l.price, l.face_price, l.buyer_fee, l.closes_at,
       l.created_at AT TIME ZONE 'UTC' AS listed_at,
       min(l.created_at AT TIME ZONE 'UTC') OVER (PARTITION BY l.ticket_id) AS first_listed_at,
       so.ordered_at AS sold_at,
       (l.price + l.buyer_fee < pr.price) AS discounted,
       (cb.sold >= :sold_out_ratio * cb.sellable) AS block_full,
       cb.sellable
FROM resale_listings l
JOIN cal_match cm ON cm.match_id = l.match_id
JOIN cal_band b ON b.match_id = l.match_id
JOIN cal_type t ON t.block_id = l.block_id
JOIN blocks bl ON bl.id = l.block_id
JOIN cal_block cb ON cb.match_id = l.match_id AND cb.block_id = l.block_id
JOIN match_prices pr ON pr.match_id = l.match_id AND pr.seat_category_id = bl.seat_category_id AND pr.ticket_type_id = l.ticket_type_id
JOIN order_items oi ON oi.ticket_id = l.ticket_id
JOIN orders o ON o.id = oi.order_id
LEFT JOIN orders so ON so.id = l.buyer_order_id;

-- 出品元の母数: 出品の対象になりうるチケット（年パスの発行分と通常購入。リセールで買った券は再出品できないので除く）
CREATE TEMP TABLE cal_listable AS
SELECT b.band, t.seat_type, bl.seating_type, o.kind AS source, count(*) AS tickets
FROM tickets tk
JOIN order_items i ON i.ticket_id = tk.id
JOIN orders o ON o.id = i.order_id AND o.kind IN ('purchase', 'season_pass')
JOIN cal_band b ON b.match_id = tk.match_id
JOIN cal_type t ON t.block_id = tk.block_id
JOIN blocks bl ON bl.id = tk.block_id
GROUP BY b.band, t.seat_type, bl.seating_type, o.kind;

ANALYZE cal_listing;

\echo '== 6. 需要帯 x 席種 x 指定席/自由席 x 出品元のリセール（チケット単位）: 出品率・成約率・最初の出品から成約までの時間の中央値（時間）・平均値引率（1 - 価格 / 定価）'
SELECT l.band, l.seat_type, l.seating_type, l.source, p.tickets,
       count(*) AS listed_tickets, round(count(*)::numeric / p.tickets, 4) AS listing_rate,
       count(*) FILTER (WHERE l.status = 'sold') AS sold,
       round(avg((l.status = 'sold')::int), 3) AS sold_rate,
       round((percentile_cont(0.5) WITHIN GROUP (ORDER BY extract(epoch FROM l.sold_at - l.first_listed_at) / 3600))::numeric, 1) AS median_hours_to_sold,
       round(avg(1 - l.price::numeric / l.face_price), 3) AS avg_discount,
       round(avg(l.discounted::int), 3) AS cheaper_than_primary
FROM cal_listing l
JOIN cal_listable p ON p.band = l.band AND p.seat_type = l.seat_type AND p.seating_type = l.seating_type AND p.source = l.source
WHERE l.status <> 'cancelled'
GROUP BY l.band, l.seat_type, l.seating_type, l.source, p.tickets
ORDER BY l.band, l.seat_type, l.seating_type, l.source;

\echo '== 6b. 出品から締切までの同じ時間で比べる: 最初の出品の時点のブロックの一次の残席のうち、そのあと締切までに一次で売れた割合（primary_sell_through）と、同じ出品の成約率（resale_sold_rate）'
\echo '   （残席 = 見込みの販売可能席数 - 出品時点までの一次販売の枚数。残席が無い出品は除く。リセールの成約率の方が高いのが期待）'
SELECT band, seat_type, seating_type,
       count(*) AS listed_tickets,
       round(avg(least(1, after / rem)), 3) AS primary_sell_through,
       round(avg((status = 'sold')::int), 3) AS resale_sold_rate
FROM (
  SELECT l.band, l.seat_type, l.seating_type, l.status,
         greatest(l.sellable - coalesce(b4.n, 0), 0) AS rem, coalesce(af.n, 0) AS after
  FROM cal_listing l
  CROSS JOIN LATERAL (SELECT sum(n) AS n FROM cal_pcount c WHERE c.match_id = l.match_id AND c.block_id = l.block_id AND c.ordered_at <= l.first_listed_at) b4
  CROSS JOIN LATERAL (SELECT sum(n) AS n FROM cal_pcount c WHERE c.match_id = l.match_id AND c.block_id = l.block_id
                        AND c.ordered_at > l.first_listed_at AND c.ordered_at <= l.closes_at) af
  WHERE l.status <> 'cancelled' AND l.listed_at = l.first_listed_at
) x
WHERE rem > 0
GROUP BY band, seat_type, seating_type ORDER BY band, seat_type, seating_type;

\echo '== 6c. 値引き（支払額が一次より安い）/ 定価寄り x ブロックが最終的に完売したか の成約率（チケット単位）。定価寄りは完売ブロックでしか売れないのが期待'
SELECT discounted AS cheaper_than_primary, block_full AS block_sold_out, count(*) AS listed_tickets,
       round(avg((status = 'sold')::int), 3) AS sold_rate
FROM cal_listing WHERE status <> 'cancelled'
GROUP BY 1, 2 ORDER BY 1, 2;

\echo '== 6d. 値下げの再出品（取り下げ → 値下げして出し直し）: 件数と、出し直した出品の成約率'
SELECT (SELECT count(*) FROM resale_listings WHERE status = 'cancelled') AS cancelled_listings,
       round(avg((n.status = 'sold')::int), 3) AS relisted_sold_rate,
       round(avg(1 - n.price::numeric / c.price), 3) AS avg_cut_from_cancelled_price
FROM resale_listings c
JOIN resale_listings n ON n.ticket_id = c.ticket_id AND n.status <> 'cancelled' AND n.created_at > c.updated_at
WHERE c.status = 'cancelled';

\echo '== 7. 一次販売のカニバリ: シーズン x 席種。リセール購入を、その時点（購入時刻まで）の同 match・block の一次販売が sold_out_ratio x sellable 以上だったかで分ける'
\echo '   resale_open = 一次が売り切れる前に出品へ流れた枚数（カニバリ）、resale_sold_out = 完売後にあふれて買った枚数（吸収）。primary_lost_share = resale_open / (一次の販売枚数 + resale_open)'
SELECT cb.season, cb.seat_type, sum(cb.sold) AS primary_sold, sum(r.open) AS resale_open, sum(r.full) AS resale_sold_out,
       round(sum(r.open) / nullif(sum(cb.sold) + sum(r.open), 0), 4) AS primary_lost_share
FROM cal_block cb
LEFT JOIN (
  SELECT ro.match_id, nt.block_id,
         count(*) FILTER (WHERE coalesce(pb.n, 0) < :sold_out_ratio * c.sellable) AS open,
         count(*) FILTER (WHERE coalesce(pb.n, 0) >= :sold_out_ratio * c.sellable) AS full
  FROM orders ro
  JOIN order_items ri ON ri.order_id = ro.id
  JOIN tickets nt ON nt.id = ri.ticket_id
  JOIN cal_block c ON c.match_id = ro.match_id AND c.block_id = nt.block_id
  CROSS JOIN LATERAL (SELECT sum(n) AS n FROM cal_pcount p WHERE p.match_id = ro.match_id AND p.block_id = nt.block_id AND p.ordered_at <= ro.ordered_at) pb
  WHERE ro.kind = 'resale'
  GROUP BY ro.match_id, nt.block_id
) r ON r.match_id = cb.match_id AND r.block_id = cb.block_id
WHERE cb.allocation = 'home'
GROUP BY cb.season, cb.seat_type ORDER BY cb.season, cb.seat_type;

\echo '== 8. 整合性（すべて 0 が正）: 売れた出品と resold の元チケット・resale の注文の食い違い、席の二重占有'
SELECT (SELECT count(*) FROM resale_listings WHERE status = 'sold') - (SELECT count(*) FROM tickets WHERE status = 'resold') AS sold_listings_minus_resold,
       (SELECT count(*) FROM resale_listings WHERE status = 'sold') - (SELECT count(*) FROM orders WHERE kind = 'resale') AS sold_listings_minus_resale_orders,
       (SELECT count(*) FROM (SELECT 1 FROM tickets WHERE seat_id IS NOT NULL AND status NOT IN ('released', 'expired', 'resold') GROUP BY match_id, seat_id HAVING count(*) > 1) x) AS double_occupied_seats;
