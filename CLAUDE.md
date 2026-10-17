# penusta-ticket

架空スタジアム「ペヌスタ」のチケット販売サイト（遊び場）。backend = Laravel 13 (API) / frontend = Next.js 16.3 / PostgreSQL 17。
構想とロードマップは `docs/concept.md`、環境のハマりどころと再構築手順は `docs/dev-notes.md`。

## 必須ルール

- artisan / composer / npm はコンテナ経由（`docker compose exec app ...` / `docker compose exec frontend ...`）。ホストの PHP は使えない
- 公開しないドキュメントは `docs/private/` 配下にカテゴリごとのディレクトリを切って置く。`docs/private/` は public 側で ignore 済みの別の private リポジトリ（penusta-private）なので、中で別にコミット・push する
- Qiita 記事は `docs/private/articles/`（構成メモ、管理表、公開済みの回）。下書きは `drafts/` に書き、公開したら同じリポジトリ内で移す（`docs/private/articles/README.md`）。public 側では README の記事一覧に Qiita のリンクを足す
- 例外: E2E（`e2e/`）はホストで実行する（`scripts/check.sh e2e`。初回は `cd e2e && npm ci && npx playwright install chromium`）
- 例外: CDK（`infra/`）と実 AWS 向けの aws CLI はホストで実行する（`scripts/check.sh infra`。初回は `cd infra && npm ci`）。プロファイルは `personal-dev-admin`（`aws sso login --profile personal-dev-admin`）
- 実 AWS への書き込み（`cdk deploy` / `destroy`、S3 への同期、Athena の DDL）はメインがユーザーに確認してから実行する。サブエージェントは synth と読み取りまで
- 時刻は UTC で保存する。JST の値は `Carbon::parse($v, 'Asia/Tokyo')->utc()` で明示変換してから保存
- Next.js のコードを書く前に `frontend/node_modules/next/dist/docs/` の該当ガイドを読む（学習済みの規約と違う）
- API 契約は `frontend/src/lib/api.ts` の型が正。backend をこれに合わせる
- matches テーブルのモデルは `FootballMatch`、events テーブルは `ActivityEvent`
- テストは別 DB `penusta_test` で走る。開発 DB `penusta` への `migrate:fresh` 等はユーザーに確認してから
- 検証は `scripts/check.sh [backend|frontend|shell|secrets|all]`（`all` は backend + frontend + shell + secrets。shell は `scripts/*.sh` の bash 3.2 構文・ShellCheck・`$VAR` 直後のマルチバイト検査。secrets はホストの gitleaks で全履歴と `docs/private/` を検査）
- 秘密情報の検査: ホストの gitleaks（`brew install gitleaks`）を pre-commit フック（`scripts/git-hooks/pre-commit`、`git config core.hooksPath scripts/git-hooks` で有効）と `check.sh secrets` で回す。フックで止まったら値を消してから再コミットし、`--no-verify` で通さない

## 委譲

メインは分解・ブリーフ作成・結果の統合・ユーザーとの対話を担当し、作業はサブエージェントへ委譲する。
メインが直接やってよいのは、数行の自明な修正、ドキュメント修正、git 操作だけ。

| 作業 | エージェント | モデル |
|---|---|---|
| コード探索（どこに何があるか） | `Explore` | haiku |
| 調査（設計比較・原因究明・外部仕様） | `researcher` | opus |
| 実装 + テスト | `implementer` | sonnet |
| check の実行と失敗の要約 | `verifier` | haiku |
| コードレビュー | `code-reviewer` | opus |
| セキュリティレビュー | `security-reviewer` | opus |

呼び出し時のモデル上書き:

- `implementer` を haiku に下げる: 既存パターンの踏襲 / 1〜2 ファイル / 設計判断なし / backend か文言・スタイル修正、をすべて満たすとき。
  frontend の Next.js 規約、トランザクション・同時実行、スキーマ変更、複数ファイル横断は sonnet のまま
- `implementer` を opus に上げる: sonnet の実装が同じ指摘でレビューを 2 回差し戻されたとき
- レビューを fable に上げる: 販売の整合性に関わる変更（二重販売防止、仮押さえの期限処理、自由席 capacity の同時実行制御、
  抽選、waiting room、決済の状態遷移）、またはユーザーが明示したとき

## 標準の流れ

1. `Explore` で現状把握
2. 設計判断が要るなら `researcher`
3. メインが方針を提示
4. `implementer`
5. `verifier`
6. `code-reviewer`。認証・認可、決済、ユーザー入力の受け口、管理画面、同時実行制御に触れた変更なら `security-reviewer` も並列で
7. 指摘を `implementer` へ戻す（最大 2 往復）
8. 結果報告

- ブリーフに書くこと: 目的 / 完了条件 / 対象ファイル / 既知の制約と除外済みの仮説 / 返してほしい形式
- レビューには実装者の説明を渡さない。仕様と diff の範囲だけ渡す
- 完了条件: `scripts/check.sh` が通る + レビューの重大指摘なし
- 同じファイルを触る `implementer` を並列に走らせない（backend / frontend の分割は可）
- コミットはユーザーが指示したときにメインが行う。サブエージェントはコミットしない

## ハーネスの育成

hooks と skills は最初から作らず、運用で必要になったものを足す。候補の台帳は `docs/harness-backlog.md`（記録時と提案時だけ読む）。

タスク完了時、次に該当したら台帳に追記するか、既存行の発生回数を増やす:

- hook 候補: 同じルール違反が `verifier` かレビューで検出された
- skill 候補: 3 ステップ以上の同じ手順を繰り返し指示した / ユーザーが同じ指示を 2 回した
- 定義の修正候補: ブリーフ不足で手戻りした / 返却形式が守られなかった / モデル配分が合わなかった

サブエージェントの返却にある「ハーネスへの気づき」は台帳へ転記する。
発生回数が 2 に達した候補は、完了報告の末尾に 1 行で昇格を提案する。追加はユーザーの承認後。却下された候補は再提案しない。
