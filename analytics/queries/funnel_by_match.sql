-- 試合別ファネル（セッション単位）。s1〜s4 は順序不問の到達数、s4_purchase_ordered は前ステップ以降の最初の時刻で辿れたもの。
-- 最後の行（is_total）が全体合計。リセールで買ったセッション（resale_purchase を含む）は一次販売のファネルではないので除く。
WITH any_t AS (
  SELECT session_id, match_id,
    min(occurred_at) FILTER (WHERE event_type = 'view_match')   AS a1,
    min(occurred_at) FILTER (WHERE event_type = 'view_seatmap') AS a2,
    min(occurred_at) FILTER (WHERE event_type = 'add_to_cart')  AS a3,
    min(occurred_at) FILTER (WHERE event_type = 'purchase')     AS a4
  FROM events_clean
  WHERE match_id IS NOT NULL
  GROUP BY session_id, match_id
  HAVING count(*) FILTER (WHERE event_type = 'resale_purchase') = 0
), o1 AS (
  SELECT session_id, match_id, a1, a2, a3, a4, a1 AS t1 FROM any_t
), o2 AS (
  SELECT o.*, min(e.occurred_at) AS t2
  FROM o1 o LEFT JOIN events_clean e ON e.session_id = o.session_id AND e.match_id = o.match_id
    AND e.event_type = 'view_seatmap' AND e.occurred_at >= o.t1
  GROUP BY ALL
), o3 AS (
  SELECT o.*, min(e.occurred_at) AS t3
  FROM o2 o LEFT JOIN events_clean e ON e.session_id = o.session_id AND e.match_id = o.match_id
    AND e.event_type = 'add_to_cart' AND e.occurred_at >= o.t2
  GROUP BY ALL
), o4 AS (
  SELECT o.*, min(e.occurred_at) AS t4
  FROM o3 o LEFT JOIN events_clean e ON e.session_id = o.session_id AND e.match_id = o.match_id
    AND e.event_type = 'purchase' AND e.occurred_at >= o.t3
  GROUP BY ALL
), flags AS (
  SELECT match_id,
    a1 IS NOT NULL AS f1,
    a1 IS NOT NULL AND a2 IS NOT NULL AS f2,
    a1 IS NOT NULL AND a2 IS NOT NULL AND a3 IS NOT NULL AS f3,
    a1 IS NOT NULL AND a2 IS NOT NULL AND a3 IS NOT NULL AND a4 IS NOT NULL AS f4,
    t1 IS NOT NULL AND t2 IS NOT NULL AND t3 IS NOT NULL AND t4 IS NOT NULL AS f4o
  FROM o4
)
SELECT match_id, GROUPING(match_id) = 1 AS is_total,
  count(*) FILTER (WHERE f1) AS s1_view_match,
  count(*) FILTER (WHERE f2) AS s2_view_seatmap,
  count(*) FILTER (WHERE f3) AS s3_add_to_cart,
  count(*) FILTER (WHERE f4) AS s4_purchase,
  count(*) FILTER (WHERE f4o) AS s4_purchase_ordered,
  round(count(*) FILTER (WHERE f2) / nullif(count(*) FILTER (WHERE f1), 0), 3) AS r2,
  round(count(*) FILTER (WHERE f3) / nullif(count(*) FILTER (WHERE f2), 0), 3) AS r3,
  round(count(*) FILTER (WHERE f4) / nullif(count(*) FILTER (WHERE f3), 0), 3) AS r4,
  round(count(*) FILTER (WHERE f4) / nullif(count(*) FILTER (WHERE f1), 0), 3) AS r_total
FROM flags
GROUP BY ROLLUP (match_id)
ORDER BY is_total, match_id;
