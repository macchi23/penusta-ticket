# 開発メモ（引き継ぎ）

最終更新: 2026-08-23（初期構築完了時点）

## 現状

初期構築完了。docker compose 7サービス（app/web/frontend/db/redis/minio/mailpit）で
試合一覧 → 座席図（SVG色分け・残席・座席グリッド）まで動く。購入フローは未実装。

- フロント: http://localhost:3100 （**3000ではない**。他プロジェクトのdevサーバーと衝突するため3100に変更済み）
- API: http://localhost:8080 、mailpit: :8025 、MinIOコンソール: :9001
- 投入済みデータ: 10試合 / 指定席3,200席+自由席8ブロック / ユーザー2,000 / 注文2.2万 / チケット4.8万 / イベント20万

## 環境の注意点（ハマりどころ）

1. **ホストのPHPは使えない**（MAMPのphp7.4が壊れている、composerも無い）。
   artisan・composerは必ずコンテナ経由:
   `docker compose exec app php artisan ...` / `docker compose exec app composer ...`
2. **`penusta:generate-demo-data` は `USE_ZEND_ALLOC=0` 必須**:
   `docker compose exec -e USE_ZEND_ALLOC=0 app php artisan penusta:generate-demo-data`
   php:8.4-alpine + 大量insertで `zend_mm_heap corrupted` が再現する（opcache無効でも発生、
   システムアロケータなら安定）。PHP 8.3への切替は composer.lock がPHP 8.4前提のため不可だった。
3. **時刻はUTC統一**: app timezone は UTC のまま。JSTの時刻を書くコード（シーダ等）は
   `Carbon::parse($date, 'Asia/Tokyo')->...->utc()` と明示変換してから保存すること。
   表示側（フロント）は `toLocaleString(..., { timeZone: "Asia/Tokyo" })` で変換済み。
   ※Laravelはdatetime保存時にタイムゾーン変換しないため、変換を忘れると9時間ずれる（初回に踏んだ）。
4. **frontendのnode_modulesはコンテナ専用ボリューム**（darwin/linuxのバイナリ差回避）。
   ホスト側node_modulesはIDE用。パッケージ追加はホストで `npm install` + コンテナ再作成
   （`docker compose up -d --force-recreate frontend`）が確実。
5. サーバーコンポーネントからのAPI呼び出しは `API_URL_INTERNAL=http://web`（コンテナ内部）、
   ブラウザからは `NEXT_PUBLIC_API_URL=http://localhost:8080`。`src/lib/api.ts` の `apiBase()` が出し分ける。
6. Next.jsは**16.3**。規約が古い知識と違う可能性があるため、書く前に
   `frontend/node_modules/next/dist/docs/` を確認（`PageProps<'/path'>` 等のグローバル型ヘルパーを使う）。

## ゼロから再構築する手順

```bash
docker compose up -d --build
docker compose exec app php artisan key:generate      # 初回のみ（.envはbackend/.env.exampleからコピー）
docker compose exec app php artisan migrate:fresh --seed --force
docker compose exec -e USE_ZEND_ALLOC=0 app php artisan penusta:generate-demo-data
# MinIOバケット（初回のみ）
docker exec penusta-ticket-minio-1 sh -c "mc alias set local http://localhost:9000 penusta penusta-secret && mc mb -p local/penusta-events"
```

## コード配置の要点

- モデル名の罠: `match` はPHP予約語のため matches テーブルのモデルは `FootballMatch`、
  events テーブルは `ActivityEvent`
- 座席図のAPI契約は `frontend/src/lib/api.ts` の型が正（バックエンドはこれに合わせる）
- 割当テンプレートは試合作成時に `match_block_allocations` へコピー展開（AllocationSeeder参照）。
  緩衝帯いじりはこのテーブルを更新すればフロントに即反映される
- 合成データの分布ルールは `app/Console/Commands/GenerateDemoData.php`。mt_srand(42)で再現可能

## 検証用クエリ（分析遊びの入口）

```sql
-- スタンド別の年齢・性別分布（仕込んだ偏りの確認）
SELECT st.name, round(avg(extract(year from age(date '2026-08-23', u.birth_date)))) avg_age,
       round(100.0*count(*) FILTER (WHERE u.gender='male')/count(*)) male_pct, count(*)
FROM tickets t
JOIN blocks b ON b.id=t.block_id JOIN stands st ON st.id=b.stand_id
JOIN orders o ON o.id=(SELECT oi.order_id FROM order_items oi WHERE oi.ticket_id=t.id LIMIT 1)
JOIN users u ON u.id=o.user_id GROUP BY st.name;
```

期待値: ゴール裏（北）= 平均39歳・男性71%、メイン = 平均51歳。
ビッグマッチ（opponent_popularity=5 の2試合）は大規模アウェー配置（S1,S2=away, S3=buffer）。

## 次にやること

[concept.md](concept.md) のロードマップ参照。直近の有力候補:
- 購入フロー（カート/仮押さえ=DBのexpires_at方式/決済モック）
- 管理画面: 割当・緩衝帯エディタ（match_block_allocations の編集UI）
- events の MinIO エクスポート（JSON Lines + `dt=` Hiveパーティション）→ DuckDB分析
