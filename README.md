# penusta-ticket

架空スタジアム「ペヌスタ」のチケット販売サイト（遊び場）。
構想は [docs/concept.md](docs/concept.md)、開発の引き継ぎ事項は [docs/dev-notes.md](docs/dev-notes.md)。

## 構成

- `backend/` — Laravel 13 (API)
- `frontend/` — Next.js 16.3 (App Router / TypeScript)
- `docker/` — php-fpm / nginx 設定
- PostgreSQL 17 / Redis / RustFS（S3 互換） / mailpit

## 起動

```bash
docker compose up -d --build
docker compose exec app php artisan migrate --seed
# USE_ZEND_ALLOC=0 は php:8.4-alpine の Zend MM 環境下で
# 大量insert時に zend_mm_heap corrupted が発生するための回避策
docker compose exec -e USE_ZEND_ALLOC=0 app php artisan penusta:generate-demo-data
```

| URL | 用途 |
|---|---|
| http://localhost:3100 | フロントエンド |
| http://localhost:8080 | API |
| http://localhost:8025 | mailpit |
| http://localhost:9001/rustfs/console/ | RustFS console |
