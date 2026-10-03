# 開発メモ（引き継ぎ）

最終更新: 2026-10-03（購入フローの骨格完了時点）

## 現状

docker compose 7サービス（app/web/frontend/db/redis/minio/mailpit）で、
試合一覧 → 座席図 → 席クリックで仮押さえ → 購入手続き → 決済モック → 完了、まで動く。
購入フローは DB だけで動く骨格（`docs/concept.md` の設計方針 3 の差し替え点は具象クラス 1 つずつ）。

- フロント: http://localhost:3100 （**3000ではない**。他プロジェクトのdevサーバーと衝突するため3100に変更済み）
- API: http://localhost:8080 、mailpit: :8025 、MinIOコンソール: :9001
- 公開ポートはすべて `127.0.0.1` に束縛している（同じ LAN から認証なしの API に届かないように）
- 認証は無い。開発用の「ユーザー切替」（画面上部のバー）で合成ユーザーになりきる。
  backend は `X-Demo-User-Id` ヘッダを guard `demo` で受けるが、`DEMO_AUTH_ENABLED=true` かつ `APP_ENV` が
  local / testing のときだけ有効。frontend 側も `DEMO_AUTH_ENABLED`（compose.yaml）で同じ判定をする
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
   キャッシュのガイドは2系統ある。`01-getting-started/08-caching.md` は Cache Components が有効な前提で、
   このリポジトリ（`next.config.ts` で `cacheComponents` 無効）では `02-guides/caching-without-cache-components.md` を読む。
   middleware は `src/proxy.ts`（`export function proxy`）に改名されている。
7. **E2E（`e2e/`）はホストで実行する**（npm コンテナ経由ルールの例外）: `scripts/check.sh e2e`。
   初回は `cd e2e && npm ci && npx playwright install chromium`。dev 環境（3100 / 8080）が起動している必要がある。

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

## 購入フローの構造（2026-10-03）

- カート = `orders.status='pending'` の行（ユーザー × 試合で 1 つ、部分ユニーク）。席 1 つにつき `tickets`（`held`）と
  `order_items` を 1 行ずつ。解除は `released`、期限切れは `expired` で行を残す（カゴ落ちを SQL で追える）
- status は `App\Enums\TicketStatus`（held / paid / released / expired）、`OrderStatus`（pending / paid / expired）
- 二重販売防止: 指定席は部分ユニーク `tickets (match_id, seat_id) WHERE seat_id IS NOT NULL AND status NOT IN ('released','expired')`
  に `ON CONFLICT DO NOTHING` で当てる。自由席は `match_block_allocations` の行を `FOR UPDATE` して capacity を数える。
  ロック順は「カート行 → allocation 行 → tickets」で統一（`CartService` / `DbUnreservedStock` / `CheckoutService`）
- 期限はカート単位（`orders.expires_at`、各 ticket にコピー、既定 10 分 = `config/ticketing.php`）。
  正しさは残席計算・席状態・確定時の時刻条件（`expires_at > now`）で保証し、`tickets:expire-holds`（毎分）は状態の整理
- 差し替え点（`backend/app/Services/`）: `Purchase/FirstComeSaleMethod`（販売方式）、`Purchase/DbUnreservedStock`（自由席在庫）、
  `Purchase/DbHoldExpiry`（期限）、`Payment/MockPaymentGateway`（決済。`TICKETING_MOCK_PAYMENT_OUTCOME=declined` で失敗を再現）
- frontend は更新系をすべて Server Action（`src/lib/actions/`）で行い、`src/lib/server-api.ts` が cookie
  （`penusta_demo_user` / `penusta_sid`）をヘッダに載せて Laravel を内部 URL で呼ぶ。ブラウザは認証付き API を直接呼ばない。
  `src/proxy.ts`（旧 middleware）がセッション cookie を発行する
- 行動ログ: `add_to_cart` / `remove_from_cart` / `purchase` / `payment_failed` は backend がトランザクション確定後に記録、
  `view_match` / `view_seatmap` は frontend の `ViewTracker` が `POST /api/events` に送る。
  `add_to_cart` は 1 クリック 1 件なので、合成データ（1 注文 1 件）とは `COUNT(*)` の粒度が違う（`SUM(quantity)` は一致）
- 同時実行の確認は `RefreshDatabase` ではできない。開発環境に同じ席へ並列 POST して 201 が 1 件になることを確認した
  （2026-10-03、8 並列）。自由席の並列は未確認
- E2E は `e2e/`、開発 DB を使い 1 回に 1 席 paid が残る。events は `session_id LIKE 'e2e0%'` で除外できる

## 購入フローの申し送り（レビューで出て、骨格では見送った点）

- 期限切れ直後に同じ席を取り直すと、1 ユーザーが席を占有し続けられる。本物の認証を入れる前に、取り直し制限か waiting room で対処
- `CheckoutService` の `charge()` が `DB::transaction(..., 3)` の再試行 closure 内にある。実決済に差し替えるときは
  order id を idempotency key として gateway に渡し、charge 後は再試行しない構成にする。ロックを持ったまま外部 API を呼ぶ形も見直す
- `MockPaymentGateway` は具象クラスの直接注入で、既定が success（fail-open）。実決済へ移るときは interface 経由で bind し、
  モックは local / testing でだけ解決できるようにする
- 仮押さえ後に `match_prices` が変わると、確定時に再計算するため画面の金額と請求額が食い違う。価格変更の通知か再確認の画面が要る
- TrustProxies 未設定で、Next 経由の全利用者が frontend コンテナの IP に見える。レート制限（`throttle:cart` / `events`）は
  実質 user / session 単位。実 IP で制限するなら frontend から `X-Forwarded-For` を渡し、TrustProxies を設定する
- `Order` / `Ticket` の `$fillable` に `status` / `total` / `expires_at` が入っている。今はリクエスト配列を `fill` していないが、
  今後の実装でもサーバー側で決めた値だけを渡すこと（code-reviewer / security-reviewer の確認項目）
- 販売条件にキックオフ時刻を入れていない（過去の試合も買える）。合成データの試合が 1 つを除いて過去日付のため。
  条件は `FirstComeSaleMethod::assertSellable` に足せばよい
- 本番相当の設定（`APP_ENV=production`、`DEMO_AUTH_ENABLED=false`）で demo guard と `/api/demo/*` が閉じることはテストで確認済み。
  本物の認証は `auth:demo` を `auth:sanctum` に書き換えるのが入口

## 次にやること

[concept.md](concept.md) のロードマップ参照。直近の有力候補:
- 管理画面: 割当・緩衝帯エディタ（match_block_allocations の編集UI）
- events の MinIO エクスポート（JSON Lines + `dt=` Hiveパーティション）→ DuckDB分析
- 販売フェーズ（年パス先行/FC先行/一般）と資格判定（`FirstComeSaleMethod` の差し替え）
- 比較実験: 自由席 capacity の `SELECT FOR UPDATE` 対 Redis カウンタ、仮押さえの DB `expires_at` 対 Redis TTL
