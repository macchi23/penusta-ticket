# penusta-ticket

架空のサッカースタジアム「ペヌスタ」（ペヌソニックスタジアム）のチケット販売サイト。個人の遊び場で、実在のサービスとは関係ない。
クラブ・試合・利用者・購入履歴はすべて架空で、合成データ生成器が作っている。

> デモ用の認証（`X-Demo-User-Id` ヘッダでユーザーを切り替える）を使っており、本番運用を想定した作りではない。

## できること

- **座席図と購入フロー**: 指定席とゴール裏自由席が混在するスタジアムで、カートに入れて 10 分仮押さえ → 決済画面（モック）→ 確定
- **販売フェーズ**: FC 先行 / 一般販売と会員資格の判定、年間パス（固定席）の発行
- **リセール**: 来場できない試合の券を出品し、別の利用者が仮押さえ → 決済で買う（一次販売とは別経路）
- **管理画面**: 試合ごとのホーム / アウェー / 緩衝帯の割当を座席図の上で塗って変更
- **データ分析**: 行動ログ（閲覧・カート・購入）を S3 互換ストレージへエクスポートし、DuckDB（ローカル）と Glue / Athena（実 AWS、CDK）で集計

## 見どころ

- **二重販売を DB で防ぐ**: 指定席は部分ユニークインデックス、自由席は割当行の `FOR UPDATE` で定員を数える。ロック順を全経路で揃え、
  ロック待ち・期限の境界・同時実行を PHPUnit で検証している（`AllocationLockTest` / `ResaleCartConcurrencyTest` など、別接続で行を握る `tests/Concerns/UsesProbeConnection` を使うテスト）
- **仮押さえの期限は時刻条件で保証**: 期限切れの status 書き換えはバッチの整理に任せ、正しさは `expires_at > now`（ロック取得後に取った now）で判定する
- **合成データ**: 7 シーズン分。成績・対戦相手・曜日・天気・価格に需要が連動し、分析すると「答えが出る」ように作っている（[docs/demo-data.md](docs/demo-data.md)）
- **Claude Code での開発運用**: メインのエージェントは分解・ブリーフ・統合だけを担い、実装・検証・レビューをサブエージェント（`.claude/agents/`）に
  委譲している。手順は [CLAUDE.md](CLAUDE.md) と `.claude/skills/step/`、運用で見つかった改善候補は [docs/harness-backlog.md](docs/harness-backlog.md) に記録して育てている

## 構成

| ディレクトリ | 内容 |
|---|---|
| `backend/` | Laravel 13（API） |
| `frontend/` | Next.js 16.3（App Router / TypeScript） |
| `e2e/` | Playwright |
| `analytics/` | DuckDB / Athena の集計 SQL |
| `infra/` | AWS CDK（S3 + Glue + Athena） |
| `docker/` | php-fpm / nginx の設定 |

ミドルウェアは PostgreSQL 17 / Redis / RustFS（S3 互換）/ mailpit。すべて Docker Compose で動く。

## 起動

```bash
cp backend/.env.example backend/.env          # DEMO_AUTH_ENABLED=true に書き換える（デモ用ユーザー切替を有効にする）
docker compose up -d --build
docker compose exec app composer install       # 終わるまで scheduler は vendor が無くて再起動を繰り返すが、終われば自動で戻る
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate:fresh --seed --force   # スタジアム・7 シーズン分の試合・管理者
# 取引データ（利用者・注文・リセール・行動ログ）。USE_ZEND_ALLOC=0 は大量 insert で起きる heap 破損（原因未特定）を避けるため
docker compose exec -e USE_ZEND_ALLOC=0 app php artisan penusta:generate-demo-data   # 今シーズン分。分析用の全量は --seasons=7
```

| URL | 用途 |
|---|---|
| http://localhost:3100 | フロントエンド（画面上部でデモユーザーを切り替える） |
| http://localhost:8080 | API |
| http://localhost:8025 | mailpit |
| http://localhost:9001/rustfs/console/ | RustFS console |

検証は `scripts/check.sh [backend|frontend|shell|secrets|e2e|infra|all]`（pint / PHPUnit / ESLint / tsc / ShellCheck / gitleaks / Playwright）。
秘密情報のコミットは、gitleaks の pre-commit フック（`git config core.hooksPath scripts/git-hooks`。ホストに `brew install gitleaks`）で止めている。

## ドキュメント

- [docs/concept.md](docs/concept.md) — 構想とロードマップ
- [docs/dev-notes.md](docs/dev-notes.md) — 設計判断・環境のハマりどころ・申し送り
- [docs/demo-data.md](docs/demo-data.md) — 合成データの仕様
- [docs/articles/](docs/articles/) — Qiita 連載「架空サカスタのチケットサイトで学ぶ」の公開済みの回
  - [（0）ゴール裏年パス勢が、架空スタジアムのチケットサイトを作る理由](https://qiita.com/macchi23/items/5b9f5670c841f5c41be8)
  - [（1）スタジアムの座席をどうテーブルにするか](https://qiita.com/macchi23/items/b6cf889842beba4f5f6a)

## ライセンス

付けていない。閲覧は自由だが、コードの再利用・再配布は許可していない。
