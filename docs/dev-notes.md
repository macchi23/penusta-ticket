# 開発メモ（引き継ぎ）

最終更新: 2026-10-11（スタジアム 2 万人化、7 シーズン分の成績シナリオ付き合成データ）

## 現状

docker compose 8サービス（app/scheduler/web/frontend/db/redis/rustfs/mailpit）+ profile 付きの analytics（DuckDB）/ aws（aws CLI）で、
試合一覧 → 座席図 → 席クリックで仮押さえ → 購入手続き → 決済モック → 完了、まで動く。
購入フローは DB だけで動く骨格（`docs/concept.md` の設計方針 3 の差し替え点は具象クラス 1 つずつ）。

- フロント: http://localhost:3100 （**3000ではない**。他プロジェクトのdevサーバーと衝突するため3100に変更済み）
- API: http://localhost:8080 、mailpit: :8025 、RustFS コンソール: :9001/rustfs/console/
- 公開ポートはすべて `127.0.0.1` に束縛している（同じ LAN から認証なしの API に届かないように）
- 認証は無い。開発用の「ユーザー切替」（画面上部のバー）で合成ユーザーになりきる。
  backend は `X-Demo-User-Id` ヘッダを guard `demo` で受けるが、`DEMO_AUTH_ENABLED=true` かつ `APP_ENV` が
  local / testing のときだけ有効。frontend 側も `DEMO_AUTH_ENABLED`（compose.yaml）で同じ判定をする
- 投入済みデータ（2026-10-11、`--seasons=7`）: 7 シーズン 123 試合（2026-27 は finished 5 / 販売途中 3 / 未販売 11）/
  スタジアム 2 万人（指定席 500 × 24 ブロック + 自由席 250 × 32 ブロック）/ ユーザー 9 万 + 管理者 1 / 注文 81 万 / チケット 145 万 / イベント 603 万。
  合成データの仕様・規模・特徴量の約束は [demo-data.md](demo-data.md)
- 管理画面 http://localhost:3100/admin （ユーザー切替で先頭の「管理者」を選ぶ）で試合ごとの割当を塗り替えられる（下の「割当エディタ」）
- events は `events:export` で RustFS（S3 互換）に、DuckDB（`scripts/analytics.sh`）で集計できる（下の「イベントログのエクスポートと分析」）

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
   クエリに Carbon をバインドすると `Y-m-d H:i:s` に整形されオフセットが落ちる。timestamptz との比較は DB セッションの
   TimeZone で解釈されるため、pgsql 接続に `'timezone' => 'UTC'` を設定して固定している（`config/database.php`）。
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
8. `notFound()` / `redirect()` を使うページで dev のオーバーレイ `Failed to execute 'measure' on 'Performance': ... cannot have a negative time stamp` が出ることがある。
   React の dev 専用計測の既知のバグ（vercel/next.js #86060 / #99032、facebook/react #37561）で、Docker VM の一時停止で
   `next-server` の単調クロックが実時間から遅れると発生する。`docker compose restart frontend` で解消する。production には計測コードが無い。
   E2E で `pageerror` を拾う場合は実行前に再起動する。
9. **app コンテナが固まって止められなくなることがある**（2026-10-13 に 1 回。原因は未特定）。
   症状: `GenerateDemoDataTest` の実行中に、コンテナ内の `php artisan` / `phpunit` / `require vendor/autoload.php` が CPU 0 のまま返らなくなる
   （`php -r 'echo 1;'` は通る。DB 側に実行中のクエリは無い）。`kill -9` でも消えず、`docker compose ps` は Up のまま。
   その後は `docker compose exec` が `error executing setns process: exit status 1`、`docker compose restart app` や
   `up -d --force-recreate app` が `cannot stop container: ... did not receive an exit event` で失敗する。
   復旧: Docker Desktop を再起動（メニューの Restart、効かなければ Quit して起動し直す）→ `docker compose up -d`。
   作り直しに失敗した直後だと app が `<id>_penusta-ticket-app-1` という一時名で上がるので、`docker compose up -d --force-recreate --no-deps app`
   で正規の名前に戻す。app は状態を持たない（`./backend` をマウントしているだけ）ので作り直しても失うものは無く、DB のデータはボリュームに残る

## ゼロから再構築する手順

```bash
docker compose up -d --build
docker compose exec app php artisan key:generate      # 初回のみ（.envはbackend/.env.exampleからコピー）
docker compose exec app php artisan migrate:fresh --seed --force   # 管理者と 7 シーズン分の試合（AS_OF 以降の 4 試合目からは未販売）もここで入る
docker compose exec -e USE_ZEND_ALLOC=0 app php artisan penusta:generate-demo-data   # 未販売の試合は飛ばす
# S3 バケット（初回のみ）。aws CLI コンテナは compose の環境変数で RustFS を向く
docker compose --profile tools run --rm -T aws s3 mb s3://penusta-events
# イベントログのエクスポートと分析用 DB（任意）
docker compose exec app php artisan events:export --from=2025-12-06 --to=2026-11-21
scripts/analytics.sh build
```

## コード配置の要点

- モデル名の罠: `match` はPHP予約語のため matches テーブルのモデルは `FootballMatch`、
  events テーブルは `ActivityEvent`
- 座席図のAPI契約は `frontend/src/lib/api.ts` の型が正（バックエンドはこれに合わせる）
- 割当テンプレートは試合作成時に `match_block_allocations` へコピー展開（AllocationSeeder参照）。
  試合ごとの変更は `Services/Admin/AllocationChangeService` 経由で行う（直接 UPDATE すると購入側との直列化と監査が抜ける）
- 合成データは `app/Support/DemoData/` と `app/Console/Commands/GenerateDemoData.php`（[demo-data.md](demo-data.md)）。用途キーごとの
  `SeededRandom` で、同じ引数なら UUID・ID・created_at まで全テーブルが一致する。COPY は `PgCopy`（`copyFromArray` の `$nullAs` は `\\N` の 3 文字を渡す。
  `\N` の 2 文字だと行の `\N` が文字列 "N" になる）

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

期待値（2026-10-11、`--seasons=7`。上のクエリの基準日を `m.kickoff_at::date` にした版）: 北 = 平均 37 歳・男性 66%、南 = 36 歳・68%、バック = 41 歳・55%、メイン = 47 歳・55%。
ビッグマッチ（opponent_popularity=5）は大規模アウェー配置（S1〜S8=away, S9=buffer）、通常は S1〜S4=away, S5=buffer。

ここは tickets / orders / users を Postgres で直接見る整合確認用。events 由来の分析（ファネル等）は `analytics/`（DuckDB）に置く。

## イベントログのエクスポートと分析（2026-10-04）

```bash
docker compose exec app php artisan events:export                      # 前日（UTC）分
docker compose exec app php artisan events:export --date=2026-08-23
docker compose exec app php artisan events:export --from=2025-12-06 --to=2026-11-21   # 全期間（合成データの範囲）
scripts/analytics.sh build     # raw → analytics/penusta.duckdb（events / events_clean）→ 成功したら curated を消して Parquet を書き直す
scripts/analytics.sh funnel    # 試合別ファネル（analytics/queries/funnel_by_match.sql）
scripts/analytics.sh shell     # DuckDB の対話モード
```

- レイアウト（バケット `penusta-events`）:
  - `raw/events/dt=YYYY-MM-DD/part-0000.jsonl.gz`: `events:export` が書く gzip JSON Lines。dt は occurred_at の **UTC** 日付。
    1 dt = 1 ファイル。再実行すると固定キーを上書きし、同じ dt 配下の他キーを消す（二重にならない）
  - `curated/events/dt=YYYY-MM-DD/part-0.parquet`: DuckDB が raw から作る。build のたびに作り直す
  - ファイルに dt 列は入れない（Athena でパーティション列と重複するとエラー）。実 S3 へは `aws s3 sync` で同じ形のまま移せる
  （実際に `scripts/aws-analytics.sh sync` で移した。下の「実 AWS」）
- E2E 由来（`session_id LIKE 'e2e00000-0000-4000-8000-%'`、`e2e/lib/session.ts` の固定部分）と `session_id = 'unknown'` は raw には残し、
  DuckDB の `events_clean` ビューで除外する。`'e2e0%'` だと合成データの UUID が偶然一致したセッション（1 件）まで消える
- DuckDB は compose の `analytics` サービス（`duckdb/duckdb:1.5.6`、profile `analytics`）。RustFS へは `rustfs:9000`。
  シークレットは `analytics/setup.sql` で毎回一時作成（PERSISTENT は平文でディスクに残るので使わない）。TimeZone は UTC に固定
- 非対話の `docker compose run` は `-T` が要る（無いと `-c` が compose のフラグとして解釈される）。イメージは distroless でシェルが無い
- `analytics/penusta.duckdb` は単一プロセスしか書けない。`shell` を開いたまま `build` しない
- ファネル（2026-10-11、`--seasons=7`）: (session_id, match_id) 単位で view_match 2,443,792 → view_seatmap 1,637,323 → add_to_cart 1,139,926 →
  purchase 809,653（orders と一致）。順序つき到達も 809,653。build は約 15 秒
- export の全期間は `--from=2021-01-01 --to=2026-12-31`（約 2.5 分）。0 件の日は既存キーを消すので、旧データの残骸もこれで消える
- 前日分は scheduler が毎日 00:30 UTC（JST 09:30）に自動で出す（下の「scheduler」）

## scheduler（2026-10-04）

compose の `scheduler` サービスが `php artisan schedule:work` を常駐させる。定義は `backend/routes/console.php`、登録内容のテストは
`tests/Feature/ScheduleTest.php`。

| コマンド | 頻度 | 重複防止 |
|---|---|---|
| `tickets:expire-holds` | 毎分 | なし（1 件ずつ `FOR UPDATE SKIP LOCKED` なので並走しても安全） |
| `events:export`（前日 UTC 分） | 毎日 00:30 UTC | `withoutOverlapping(60)`（Redis ロック、期限 60 分） |

```bash
docker compose logs -f scheduler                                   # 毎分の Running [...] DONE / FAIL と失敗時の Log::error
docker compose exec scheduler php artisan schedule:list            # 次回実行時刻
docker compose exec scheduler php artisan schedule:test --name=events:export   # 今すぐ 1 回（--name に php artisan は付けない）
```

- `schedule:work` は毎分 `schedule:run` を別プロセスで起動するので、console.php・コマンド・`.env` の変更は再起動なしで次の分から効く。
  compose の environment を変えたときだけ `docker compose up -d scheduler`
- 失敗時は `onFailure` がコマンドの出力（`storage/logs/schedule-<sha1>.log`、毎回上書き）を `Log::error` に渡し、scheduler だけに
  設定した `LOG_STACK=single,stderr` で docker logs に出る。`config:cache` を使うとこの上書きが効かなくなる（bind mount で app と
  scheduler が同じキャッシュを読む）ので使わない
- `schedule:test` の表示は終了コードを見ずに DONE を出す。失敗は直後の `local.ERROR: Scheduled command failed` で判断する
  （`schedule:run` 経由なら FAIL と出る）
- 止まっていた間の実行は取り戻さない。Mac のスリープ等で 00:30 UTC を逃した日は `events:export --date=` で出し直す（冪等な上書き）
- 強制終了で `events:export` のロックが残ったら最大 60 分スキップされる。急ぐなら `php artisan schedule:clear-cache`
- `restart: on-failure`（`unless-stopped` だと Docker 再起動後に scheduler だけ上がり、db が無いまま毎分失敗する）、
  `stop_grace_period: 2m`（停止時に実行中の export を待つ）
- app と scheduler は同じイメージ `penusta-ticket-php` を使う（別名だと片方だけ再ビルドして PHP のパッチバージョンがずれた。
  8.4.24 / 8.4.26 で実際に発生）。Dockerfile を変えたら `docker compose build app` 1 回で両方に効く
- scheduler には `USE_ZEND_ALLOC=0` を付けていない（付けると memory_limit が効かなくなる懸念）。PHP 8.4.26 で全期間の export を
  env なしで 3 回流し、heap 破損は出なかった。必要なのは大量 insert の `penusta:generate-demo-data` だけ
- scheduler は root で動き、bind mount の `storage/logs` にファイルを作る。Docker Desktop for Mac ではホストユーザー所有に見えるので
  問題ないが、Linux ホストで root 所有の `laravel.log` が先にできると php-fpm（www-data）が書けなくなる。Linux では `user: www-data` を付ける

## 購入フローの構造（2026-10-03）

- カート = `orders.status='pending'` の行（ユーザー × 試合で 1 つ、部分ユニーク）。席 1 つにつき `tickets`（`held`）と
  `order_items` を 1 行ずつ。解除は `released`、期限切れは `expired` で行を残す（カゴ落ちを SQL で追える）
- status は `App\Enums\TicketStatus`（held / paid / released / expired / resold）、`OrderStatus`（pending / paid / expired）
- 二重販売防止: 指定席は部分ユニーク `tickets (match_id, seat_id) WHERE seat_id IS NOT NULL AND status NOT IN ('released','expired','resold')`
  に `ON CONFLICT DO NOTHING` で当てる。自由席は `match_block_allocations` の行を `FOR UPDATE` して capacity を数える。
  ロック順は「カート行 → allocation 行 → tickets」で統一（`CartService` / `DbUnreservedStock` / `CheckoutService`）。
  2026-10-06 から指定席も allocation 行を `FOR SHARE` で取る（下の「割当エディタ」のロック設計）
- 期限はカート単位（`orders.expires_at`、各 ticket にコピー、既定 10 分 = `config/ticketing.php`）。
  正しさは残席計算・席状態・確定時の時刻条件（`expires_at > now`）で保証し、`tickets:expire-holds`（毎分）は状態の整理
- 差し替え点（`backend/app/Services/`）: `Purchase/FirstComeSaleMethod`（販売方式）、`Purchase/DbUnreservedStock`（自由席在庫）、
  `Purchase/DbHoldExpiry`（期限）、`Payment/MockPaymentGateway`（決済。`TICKETING_MOCK_PAYMENT_OUTCOME=declined` で失敗を再現）
- frontend は更新系をすべて Server Action（`src/lib/actions/`）で行い、`src/lib/server-api.ts` が cookie
  （`penusta_demo_user` / `penusta_sid`）をヘッダに載せて Laravel を内部 URL で呼ぶ。ブラウザは認証付き API を直接呼ばない。
  `src/proxy.ts`（旧 middleware）がセッション cookie を発行する
- 行動ログ: `add_to_cart` / `remove_from_cart` / `purchase` / `payment_failed` は backend がトランザクション確定後に記録、
  `view_match` / `view_seatmap` は frontend の `ViewTracker` が `POST /api/events` に送る。
  指定席の `add_to_cart` は 1 クリック 1 件（`quantity: 1`）、自由席は一括反映 1 回につき 1 件（`quantity` = 枚数）。
  合成データ（1 注文 1 件）とは `COUNT(*)` の粒度が違うことがある（`SUM(quantity)` は一致）
- 自由席は `PUT /api/matches/{match}/cart/blocks/{block}/items` に券種ごとの枚数を送って一括反映する（増減を 1 トランザクションで）。
  `POST /cart/items` の `{block_id}`（1 枚追加）も残っているが frontend からは使っていない
- 同時実行の確認は `RefreshDatabase` ではできない。開発環境に同じ席へ並列 POST して 201 が 1 件になることを確認した
  （2026-10-03、8 並列）。自由席の並列は未確認。
  ロックの待ち合いは 2026-10-06 から PHPUnit で検証している（`tests/Concerns/UsesProbeConnection`。下の「割当エディタ」）
- E2E は `e2e/`、開発 DB を使い 1 回に 1 席 paid が残る。events は `session_id LIKE 'e2e00000-0000-4000-8000-%'` で除外できる

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
- 販売条件にキックオフ時刻を入れていない。`status` を `finished` に変える処理も無いので、`on_sale` の試合はキックオフ後も買える
  （2026-10-09 の security-reviewer で再指摘。7 シーズン化で AS_OF 直後に販売中の試合が並ぶので表に出やすい）。割当編集はキックオフで止まるので基準が食い違う。
  条件は `FirstComeSaleMethod::assertSellable` に足すか、キックオフで `finished` にする処理を置く。購入フローの変更なので Fable レビュー
- 本番相当の設定（`APP_ENV=production`、`DEMO_AUTH_ENABLED=false`）で demo guard と `/api/demo/*` が閉じることはテストで確認済み。
  本物の認証は `auth:demo` を `auth:sanctum` に書き換えるのが入口
- `now` はロック取得後に取る（`CheckoutService` / `CartService` とも）。サービスが使った `now` を戻り値（`CheckoutResult` / `CartChange` /
  `CurrentCart`）で返し、コントローラはレスポンスとイベント記録にそれを使う。テストは `CartTest::expireAfterFirstLock()`（最初の
  `for update` の直後に時刻を期限後へ進める）で、ロック前に `now` を取る退行を検出する
- 「同じトランザクション内で解放してから取り直す」処理は仮押さえの延長に化けやすい（`setUnreservedQuantities` で一度出て修正済み）。
  今後 Redis 版などを作るときも、期限の付け直しは「操作前に有効な held が 0 件」の条件で判定する

## 割当エディタ（2026-10-06）

管理者が試合ごとにブロックの割当（home / away / buffer / closed）を変える。画面は `/admin` → `/admin/matches/{id}/allocations`
（座席図の上でブラシを選んで塗り、差分を保存）。結果は一般利用者の座席図と購入可否にそのまま効く。

### 既存の開発 DB に入れる手順

```bash
docker compose exec app php artisan migrate                               # users.role、allocation の check 制約、監査テーブル
docker compose exec app php artisan db:seed --class=AdminUserSeeder       # 管理者（admin@penusta.example、パスワードはランダム）
```

AdminUserSeeder は冪等。未販売の試合は 2026-27 の AS_OF（2026-10-08）以降の 4 試合目から。`next.config.ts` を変えたので、
403 / 401 のページが出ないときは `docker compose restart frontend`。

### 決めたこと

- 認可: `users.role`（customer / admin、check 制約、fillable 外）と Gate `manage-allocations`。`/api/admin/*` は
  `auth:demo` + `can:manage-allocations` + throttle（`admin-read` 120/分、`admin-write` 30/分）を `routes/api.php` の 1 箇所で掛ける。
  サービス（`isAdmin()`）と FormRequest でも再検査する。demo が無効なら `auth:demo` で 401 になり、admin も閉じる
- 編集ルールは試合の状態ではなくチケットの有無で決める。consuming（paid か期限内の held）が 1 枚でもあるブロックは変更を拒否する。
  期限切れの held は数えない。キックオフ後の試合は読み取り専用。1 回の保存は全件適用か全件拒否
  - dev の合成データは全試合の home / away に paid があるので、緩衝帯の拡大を試せるのは未販売の 2 試合だけ
  - ブロック単位の属性なので「空き席だけに適用」はできない。やるなら席単位の割当が要る
- 楽観的な同時編集: PATCH はブロックごとに `expected`（画面に見えていた値）を送り、現在値と違えば 409 `stale`。
  差分だけを送るので、触っていないブロックは競合しない
- 監査: `match_block_allocation_changes`（from / to、changed_by、change_set_id、changed_at UTC）に同じトランザクションで書く。
  FK はすべて restrict（試合やユーザーを消しても履歴が消えない）。events（ActivityEvent）には載せない。events は利用者の行動ログで、
  合成データと粒度を揃えているため。分析に使うときは監査表を別データセットとして export する
- API 契約は `frontend/src/lib/api.ts` の `AdminMatch` / `AdminAllocations` / `AllocationChange` / `AllocationConflict`。
  409 は `{code, message, details: {blocks}}`。`server-api.ts` の許可リストは `Record<ApiErrorCode, true>` で、コードの追加漏れは tsc で落ちる

### ロック設計

| 経路 | allocation 行 | その後 |
|---|---|---|
| 管理者の保存（`AllocationChangeService::apply`） | 対象を block_id 昇順で `FOR UPDATE`（`SET LOCAL lock_timeout`、既定 3000ms） | ロック後に `now` → キックオフ判定 → チケットはロックせずに数える → update + 監査 |
| 指定席の仮押さえ（`CartService::addItem`） | `FOR SHARE` | tickets を insert（partial unique index が最後の砦） |
| 自由席の仮押さえ（`addItem` / `setUnreservedQuantities` → `DbUnreservedStock`） | `FOR UPDATE`（従来どおり） | capacity を数えて insert |
| 確定（`CheckoutService::checkout`） | held のチケットがあるブロックだけ、block_id 昇順に 1 行ずつ席種別のモードで | ロック後に `now` → 期限判定 → tickets `FOR UPDATE` → paid |

- 購入側はチケットを consuming にする前に必ず allocation 行をロックし、ロックした読み取りの値で販売可否を決める
  （`Services/Purchase/AllocationLocks`）。管理者は `FOR UPDATE` するので、判定と購入が直列化される
- 指定席を `FOR UPDATE` にしないのは、checkout が決済呼び出しの間もロックを持つため。同じブロックの指定席購入が決済待ちで並んでしまう
- 同じトランザクション内で同じ行を `FOR SHARE` から `FOR UPDATE` に上げない（同じブロックで 2 人が SHARE を握って待ち合う）。
  モードは不変の `seating_type` で決め打ちにする。checkout で 2 種別をクエリ 2 本に分けると昇順が崩れて管理者とデッドロックしうるので、1 行ずつ回す
- `addItem` / `setUnreservedQuantities` は allocation ロックの後に `now` を取り直さない。カート期限の判定は orders ロック直後の `now` で
  済んでいて、ここで進めると「期限切れ held を残したまま `expires_at` だけ延長」になり、checkout で新しい分ごと期限切れになる
  （一度そう直してレビューで差し戻した）。checkout だけはロック後に取る
- checkout が released / expired のチケットのブロックまでロックすると、追加と削除を繰り返して多数のブロックを握れてしまう（レビューで検出）。held に絞っている
- 購入側の 55P03 / 40P01（ロック待ちのタイムアウト、デッドロックの再試行切れ）は api/* で 503 `busy` にし、SQL を返さない。
  管理者側の 55P03 は 409 `allocation_busy`
- `apply` は最上位のトランザクションでしか呼べない（`SET LOCAL` と 55P03 の abort が外側に及ぶ）。HTTP テストも `DatabaseTruncation` で書く

### テスト

- `tests/Concerns/UsesProbeConnection`: 別接続 `probe` で行を握り、本接続に `lock_timeout=200ms` をかけて「待たされる / 通る」を判定する。
  `DB::connection()->beforeExecuting` で「ロック直前に別接続がコミットした」状況を作れる。`DatabaseTruncation` は終了時に自分で truncate する
  （残すと後続の `RefreshDatabase` 系の件数が壊れる）
- `AllocationLockTest`（購入側）、`AllocationChangeServiceTest`（管理者側）、`Admin/AdminAllocationApiTest`、`Admin/BusyConversionTest`
- E2E `e2e/tests/admin-allocations.spec.ts`: 403 / 401、塗って保存、一般利用者の購入不可、ロック表示、他の管理者との競合。
  `afterAll` でカートと割当を開始時の値に戻す。入口（画面上部のユーザー切替を操作してからの 403 / 401、管理画面を開いたまま一般ユーザーに
  切り替えると権限なしの表示に変わる、`/matches/admin` の 404）は割当に依存しないので別の describe に分けている
- 実 UI のユーザー切替（Server Action で cookie を変える）の後に `goto` するテストは、バーに切替後のユーザー名が出るまで待つ。
  待たないと古い cookie のまま遷移する

### 申し送り

- E2E は未販売の試合に依存する。2026-27 最終節（2027-06）のキックオフを過ぎると編集できる試合が無くなり、`pickEditableUnsoldMatch` が理由付きで落ちる。
  `AddUnsoldMatchesSeeder` は廃止した（MatchSeeder が DemoScenario から 7 シーズン分を作る）。`DemoScenario::AS_OF_JST` を進めて再シードする
- 一般利用者の座席図の「購入不可」は色とカーソルだけで、文言が無い（E2E は `data-allocation` で見ている）
- ルートの `app/not-found.tsx`（日本語）を足した。それまでは `/matches/admin` のような打ち間違いで Next 既定の英語の 404 が出て、
  管理画面の不具合と見分けにくかった（ブラウザ確認で実際に取り違えた）。確認手順の URL は `http://localhost:3100/admin` のように全部書く
- demo 有効時は cookie を管理者の id に書き換えれば誰でも管理者になれる（demo guard の前提どおり）。本物の認証は `auth:demo` の差し替えから
- CORS は `CORS_ALLOWED_ORIGINS`（既定 `http://localhost:3100`）に限定した。ブラウザから API を直接呼ぶ箇所は無い
- `{match}` / `{block}` は `Route::pattern` で数値に限定している（非数値は 404）
- 購入側には `lock_timeout` を掛けていない。管理者の保存は短いので待つ側にしている

## イベントログ分析の申し送り（2026-10-04）

- ~~scheduler が動いていない~~ → 解消（2026-10-04）。compose の `scheduler` サービスで `tickets:expire-holds`（毎分）と
  `events:export`（毎日 00:30 UTC）を自動実行する（上の「scheduler」）
- S3 互換ストレージは MinIO から RustFS（`rustfs/rustfs:1.0.1`、Apache-2.0）に置き換えた（2026-10-04）。`minio/minio` と `minio/mc` は
  Docker Hub から削除され、quay.io も匿名 pull 不可で、ゼロから再構築できなくなっていた（GitHub の minio/minio も archived）。
  mc の代わりに aws CLI コンテナ（profile `tools`）を使う。イメージはタグ + digest で固定。タグ無しのイメージ（`axllent/mailpit` 等）は
  手元のキャッシュで動いてしまい配布終了に気づけないので、ゼロから再構築するときに確かめる。旧 `minio-data` ボリュームは削除済み。
  minio コンテナが残っている環境では 9000/9001 が塞がるので、`docker compose up -d --remove-orphans` で旧コンテナを片付ける
- 合成データの購入セッションは、各ステップを前ステップの 1〜18 / 1〜12 / 1〜6 分後に積み上げる（2026-10-04 修正、旧実装は購入時刻からの
  独立オフセットで順序が約 2 割逆転していた）。実アプリの view_match → view_seatmap は数秒差なので、分単位の間隔はまだ長め。
  秒単位にすると乱数の呼び出しが変わり orders 以降の合成データがすべて変わる
- pint は `use` 漏れを検出しない（`catch (Throwable)` が名前空間内のクラスを指して何も捕まえないバグをレビューで検出）。
  静的解析（larastan）を `scripts/check.sh` に足す候補

## 実 AWS（S3 + Glue/Athena）（2026-10-05）

構成は `infra/`（CDK TypeScript、ホスト実行、`aws-cdk-lib` 2.272.0 / `aws-cdk` 2.1144.0 固定）。スタック `PenustaAnalytics`（us-east-1 固定）:
S3 `penusta-analytics-<account>`、Glue DB `penusta_analytics`、Glue テーブル `events_raw`（Hive JSON SerDe）/ `events_curated`（Parquet、
どちらも partition projection）、Athena WorkGroup `penusta-analytics`。学習メモは [dea-notes.md](dea-notes.md) の 14.。

### 前提

```bash
aws sso login --profile personal-dev-admin     # 期限切れのたびに
cd infra && npm ci                              # 初回のみ（ホストで実行）
```

- プロファイルは `PENUSTA_AWS_PROFILE`（既定 `personal-dev-admin`）。`scripts/aws-analytics.sh` は `--profile` で渡し、環境変数のキーと
  `AWS_ENDPOINT_URL*`（RustFS 向け）は外す。操作前に Account が `sso_account_id` と一致、Arn が `AWSReservedSSO_`、バケット名が
  `penusta-analytics-<account>` であることを確かめる
- CDK と実 AWS 向けの aws CLI はホスト実行（コンテナ経由ルールの例外。CLAUDE.md 参照）

### 作成

```bash
cd infra && npx cdk deploy --profile personal-dev-admin
```

`LegacyStackSynthesizer` + アセットなしなので bootstrap 不要（初回 26 秒、テーブル追加 9 秒）。

### 同期と照合

```bash
scripts/analytics.sh build                                   # 先に curated を作り直す
scripts/aws-analytics.sh sync                                # RustFS → 一時ディレクトリ → 実 S3
scripts/aws-analytics.sh compare events_curated              # DuckDB のファネルと照合
scripts/aws-analytics.sh compare events_raw
scripts/aws-analytics.sh funnel [table]                      # 試合別ファネルだけ見る
scripts/aws-analytics.sh query <sql-file>                    # SELECT / WITH のみ（例: analytics/athena/funnel_by_match.sql）
```

- 期待値（2026-10-05・旧データ 20 万行。7 シーズン化の後は未計測）: sync は raw 351 / curated 351 オブジェクト。`compare` はどちらも ok（ファネル合計 89,764 / 56,160 / 35,970 / 22,441 / 22,441）。
  events_curated は 19.5MB スキャン・3.1 秒、events_raw は 19.7MB・5.4 秒

### Iceberg（curated の Iceberg 化）

SQL は `analytics/athena/iceberg/01〜08`。`ddl` は制限なしで実行する（`{{bucket}}` 置換、先頭コメント行は除去してから送る）。

```bash
I=analytics/athena/iceberg
scripts/aws-analytics.sh ddl   $I/01_create.sql         # CTAS（events_iceberg を作る）
scripts/aws-analytics.sh compare events_iceberg
scripts/aws-analytics.sh query $I/03_snapshots.sql      # スナップショット一覧（append が 1 つ）
scripts/aws-analytics.sh ddl   $I/04_merge.sql          # MERGE（冪等。件数は変わらない）
scripts/aws-analytics.sh query $I/03_snapshots.sql      # overwrite が増えた
# 05（time travel）は最初の snapshot_id に置き換えたコピーを作って query する（リポジトリのファイルは書き換えない）
scripts/aws-analytics.sh ddl   $I/06_optimize.sql       # OPTIMIZE
scripts/aws-analytics.sh ddl   $I/07_snapshot_age.sql   # snapshot age を 60 秒に
sleep 60
scripts/aws-analytics.sh ddl   $I/08_vacuum.sql         # VACUUM
scripts/aws-analytics.sh compare events_iceberg
```

- 結果（2026-10-05・旧データ）: CTAS 6.8MB スキャン・4.3 秒 → MERGE（2026-01-10 の 233 行、0.6MB）で overwrite スナップショット、件数は 204,353 のまま →
  time travel（`FOR VERSION AS OF` 最初の snapshot_id）は before / current とも 204,353、スキャン 0 バイト（メタデータで count）→
  OPTIMIZE で replace スナップショット（0.18MB）→ VACUUM でスナップショット 3 → 1、`iceberg/events/` は 22 オブジェクト 4.1MB、compare は ok
- 実行時の注意: `ddl` の実行が `query` と違い自動許可されない（Claude Code から流すときは都度承認）

### 片付け

```bash
scripts/aws-analytics.sh teardown     # Iceberg テーブルの DROP とスタック削除
scripts/aws-analytics.sh status       # S3 / Glue DB / WorkGroup / スタックがすべて absent であること
```

- スタックが `ROLLBACK_COMPLETE` などで Outputs が無いと teardown は止まる。その場合は `aws cloudformation delete-stack --stack-name PenustaAnalytics --region us-east-1 --profile personal-dev-admin` を手で
- teardown を `cdk destroy` にしていないのは、CDK（JS SDK）が同名プロファイルの static キーを優先しうるため。`delete-stack` に一本化した
- Glue の Database / Table はタグ付けできないので、タグ検索だけでは残存確認にならない

片付け結果（2026-10-05）: `teardown --yes` で Iceberg の DROP → `s3 rm` → `delete-stack` + wait まで通り、
`status` は stack / S3 バケット / Glue DB / WorkGroup / タグ付きリソース（0 件）がすべて absent。
us-east-1 の `CDKToolkit`（2026-03-21 作成、別件の bootstrap）は対象外なので残っている。

### 費用の目安

- Athena は $5/TB・MB 切り上げ・1 クエリ最低 10MB。DDL と失敗クエリは無課金。WorkGroup でスキャン 3GB/クエリの上限を enforce している（7 シーズン化で 1GB から上げた。deploy は未実施）
- 今回の全クエリで $0.001 未満の見込み。Glue Data Catalog は 100 万オブジェクト・月 100 万リクエストまで無料。S3 は 2025-07-15 より前に作ったアカウントなら旧無料枠（12 か月）、以降はクレジット制の新プラン

### 注意

- `sync` は必ず `scripts/analytics.sh build` の直後に。build 前だと古い curated が S3 に載る
- `sync` の `--delete` は `raw/events/` と `curated/events/` に限る。`iceberg/events/` には及ばない
- Iceberg テーブルは CFn の外（Athena の DDL）で作るので、Athena の DROP TABLE でデータも消える。teardown で DROP してからスタックを消す
- partition projection の範囲は 2021-01-01〜2027-12-31 に固定（コードは 2026-10-08 に変更、deploy は未実施）。合成データの範囲外の日付を足すときは `infra/` のテーブル定義を直す
  （`NOW+2YEARS` のような広い範囲は候補日ごとに S3 LIST が走る。funnel 1 回で約 4,000 LIST になり、無料枠 2,000/月を超える）
- export 先（`events:export`）を実 S3 に切り替えていない。app と scheduler が `.env` を共有して毎日実 AWS に書くことになり、SSO 期限切れで失敗し、
  Laravel の s3 ディスクに token キーが無く `AWS_SESSION_TOKEN` が落ちるため
- クローラは使わない（スタック外にテーブルができる、型推論が揺れる、DPU 時間課金・最低 10 分）
- us-east-1 には別件の CDKToolkit（2026-03-21 作成）が既にある。今回は使っておらず、消してもいない
- macOS の `/bin/bash` 3.2 での罠（`aws-analytics.sh` で踏んだ）: `local` 変数を EXIT trap で参照すると `set -u` で unbound /
  `if` や `&&` の条件部から呼んだ関数内では `set -e` が効かない / `$VAR` の直後にマルチバイト文字が続くと変数名に取り込まれる（`${VAR}` と書く）
- Claude Code の auto mode は `cdk deploy` を拒否した。`!` でユーザーが実行し、`.claude/settings.local.json` に範囲を絞って許可を足した
  （cdk diff / deploy と `aws-analytics.sh` の sync / query / funnel / compare）。`query` は SELECT / WITH だけ通し、書き込みは `ddl` に分けてある
  （自動許可で任意 SQL を流せると DROP / UNLOAD が通ってしまう）。`ddl` / `teardown` / `cdk destroy` は都度承認

## リセール（2026-10-12、仮押さえは 2026-10-13）

- `resale_listings` に出品 1 件 1 行（active / sold / cancelled / expired）。部分ユニーク `(ticket_id) WHERE status IN ('active','sold')` で二重出品を防ぐ。
  金額（price / seller_fee / buyer_fee / payout）は出品時に確定して保存。設定は `config/ticketing.php` の `resale.*`
- 購入は仮押さえ経由だけ（`ResaleCartService`）: hold → 決済画面（`/matches/[id]/resale-checkout`）→ checkout。旧即時購入（`POST /resale-listings/{id}/purchase`）は削除した
- 仮押さえは `resale_holds`（held / released / expired / purchased、`expires_at`、purchased のときだけ `order_id`）。行は残すのでカゴ落ちを SQL で追える。
  有効な仮押さえ = `held AND expires_at > now`（`ResaleHold::scopeLive` の 1 か所）。正しさは時刻条件で保証し、期限切れの status 書き換えは整理
  - 部分ユニーク `(listing_id) WHERE held`（同じ出品を 2 人が押さえない）と `(user_id, match_id) WHERE held`（1 人 1 試合 1 件）が最終防衛線
  - `expires_at = min(now + hold_minutes, closes_at)`。同じ出品の再 hold は変更なし（延長しない）、別の出品は 409 `resale_hold_exists`
  - 同じ (user, listing) は released / expired の後 30 分押さえ直せない（409 `resale_hold_cooldown`、`resale.hold_cooldown_minutes`）。起点は released なら `updated_at`、期限切れなら `expires_at`（整理の遅れで延びない）
  - 押さえ中の出品は他人の一覧から消え（自分の分もカート側に出す）、出品者の取り下げは 409 `listing_held`。マイチケットは `on_hold` / `can_cancel` で同じ述語を使う
- ロック順は全操作で advisory(7301, buyer×match) → tickets → resale_listings → resale_holds の部分列。`now` は最後のロックの後で取り、結果オブジェクトで返してイベント時刻にも使う。
  `SET LOCAL lock_timeout`（既定 2.5 秒）の 55P03 は 409 `listing_unavailable`
  - hold 内の期限切れ整理と `resale:expire-listings`（毎分、listings と holds）は `UPDATE ... WHERE id IN (SELECT ... FOR UPDATE SKIP LOCKED)`。行ロックの交差は避けるが、
    部分ユニーク索引の待ちによる交差は残りうる（40P01 → 再試行、負けた側は 23505 → `listing_unavailable`）
- checkout（`ResaleSettlement::settle` を共有）: 元チケットを `resold` にしてから新チケットを INSERT（逆順だと部分ユニークに当たる）。order は pending を経由せず kind=resale・paid。
  purchased 済みの再送は同じ order を 200（冪等）。期限切れ・released は hold を expired にコミットしてから `cart_expired`。決済失敗は全体 rollback で hold は held のまま。
  allocation 行はロックしない（paid が 1 減って 1 増えるだけ）
- イベント: `resale_add_to_cart`（hold 新規時）/ `resale_remove_from_cart`（実際に released にしたとき）/ `resale_purchase`（新規 checkout）/ `payment_failed`（source resale）。
  一次の funnel（`add_to_cart` / `purchase` の完全一致）には混ざらない
- 出品中のチケットは paid のまま（在庫・割当エディタ・年パス発行の判定を変えない）で、QR だけ返さない。取り下げは active かつ有効な hold なしなら期間外でも可
- 年パス券を売っても `season_pass_seat_id` は元チケットに残るので、`SeasonPassIssuer` は再発行しない。新チケットの `season_pass_seat_id` は NULL
- API の 404 は汎用 `{code: not_found}`（既定メッセージにモデル名と id が出るため。`bootstrap/app.php`）
- tickets.status に値を足すときは、部分ユニーク・CHECK・`consumingInventory`・`OrderResource` の絞り込み・analytics の SQL を全部見直す
- 申し送り:
  - 決済をトランザクション内で呼んでいる（mock なので問題ないが、実決済にするなら hold id か order id を冪等キーにして外出し）
  - 入場ゲートを作るときは「paid かつ active 出品なし」で判定する
  - クールダウンは同じ出品だけ。別の出品を順に押さえ続ける占有は残る（1 人 1 試合 1 件なので影響は 1 出品ずつ）

## 次にやること

[concept.md](concept.md) のロードマップ参照。直近の有力候補:
- ~~管理画面: 割当・緩衝帯エディタ~~ → 完了（2026-10-06。上の「割当エディタ」）
- ~~実 S3 + Glue/Athena~~ → 完了（2026-10-05。上の「実 AWS」）
- 販売フェーズ（年パス先行/FC先行/一般）と資格判定（`FirstComeSaleMethod` の差し替え）
- 比較実験: 自由席 capacity の `SELECT FOR UPDATE` 対 Redis カウンタ、仮押さえの DB `expires_at` 対 Redis TTL
