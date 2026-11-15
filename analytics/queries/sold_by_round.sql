-- 2025 シーズン（春秋制の 2025）の節ごとの販売枚数。PostgreSQL 版（season_id = 5 決め打ち）の DuckDB 版で、答え合わせに使う。
-- device IS NOT NULL は合成データだけに絞る条件。
SELECT m.round, CAST(m.opponent_popularity AS VARCHAR) AS popularity, m.weather,
       sum(CAST(e.payload->>'quantity' AS INTEGER)) AS sold
FROM events e JOIN matches m ON m.id = e.match_id
JOIN seasons s ON s.id = m.season_id AND s.year = 2025 AND s.format = 'spring_autumn'
WHERE e.event_type = 'purchase' AND e.device IS NOT NULL
GROUP BY 1, 2, 3 ORDER BY 1;
