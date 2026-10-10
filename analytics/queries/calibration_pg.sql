-- 需要モデルの調整用集計（PostgreSQL）。合成データの生成直後に、席種・シーズンごとの販売率と完売を確認する。
-- 前提: scale=1 で生成したテスト DB（penusta_test）。scale が小さいと販売可能席数の分母が合わない。
--   docker compose exec -e DB_DATABASE=penusta_test -e USE_ZEND_ALLOC=0 app php artisan penusta:generate-demo-data --seasons=7 --scale=1
-- 実行:
--   docker compose exec -T db psql -U penusta -d penusta_test -f - < analytics/queries/calibration_pg.sql
-- 分母（販売可能席数）= その試合で home に割り当てたブロックの capacity − 年パスの固定席。固定席の数は SeasonPassSeating::split と同じ式
-- （ゴール裏 65% / バック 25% / 残りメイン）で、シーズンの年パス会員数から出す。
-- 販売率は年パスの引換（season_pass 券種）を除いた一般販売の枚数 / 販売可能席数。
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
  FROM tickets tk JOIN order_items i ON i.ticket_id = tk.id
  WHERE i.ticket_type_id <> (SELECT id FROM ticket_types WHERE code = 'season_pass')
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

\echo '== 5. 年パスの空席率 = 1 - 引換枚数 / (年パス会員数 x 試合数)'
CREATE TEMP TABLE cal_redeemed AS
SELECT tk.match_id, count(*) AS redeemed
FROM tickets tk JOIN order_items i ON i.ticket_id = tk.id
WHERE i.ticket_type_id = (SELECT id FROM ticket_types WHERE code = 'season_pass')
GROUP BY tk.match_id;

SELECT cm.season, p.holders, count(*) AS matches,
       round(1 - sum(coalesce(r.redeemed, 0))::numeric / nullif(p.holders * count(*), 0), 3) AS empty_rate
FROM cal_match cm
JOIN cal_pass p ON p.season_id = cm.season_id
LEFT JOIN cal_redeemed r ON r.match_id = cm.match_id
GROUP BY cm.season, p.holders ORDER BY cm.season;
