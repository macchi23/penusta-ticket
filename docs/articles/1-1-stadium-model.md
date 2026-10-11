<!--
下書き（docs/article-series.md 1-1）。Qiita には「## はじめに」から下を貼る。# の行は Qiita のタイトル欄に入れる。
TODO の箇所は自分の言葉に置き換える。公開前に article-series.md の「公開前チェック」を通す。
題名・タグは docs/articles/README.md で管理する。
-->

# スタジアムの座席をどうテーブルにするか ― 架空サカスタのチケットサイトで学ぶ（1）

## はじめに

架空のサッカースタジアム「ペヌソニックスタジアム（通称ペヌスタ）」のチケット販売サイトを、遊び場として作っています。
ちゃんと動くチケットサイトを土台に、販売方式の実験やデータ分析（AWS の DEA / MLA の学習）へ広げていくのが目的です。

<!-- TODO: なぜチケットサイトを題材にしたか、自分の動機を 2〜3 行 -->

<!-- TODO: 1-0 と重なるので、この節は 1-0 へのリンクで短くする（article-series.md 書き方のルール）。1-0: https://qiita.com/macchi23/items/5b9f5670c841f5c41be8 -->

この記事では、最初に決めた「スタジアムの構造をどうテーブルで表すか」を扱います。

- 指定席と自由席を、同じテーブルでどう扱うか
- ホーム / アウェーの割当を、試合ごとにどう持つか
- 割当のテンプレートを、試合に「コピー」するか「参照」するか

構成は Laravel（API）+ Next.js + PostgreSQL です。

## スタジアムの構造

ペヌスタはこういう構造にしました。

| スタンド | ブロック | 席の種類 | 席種（価格帯） |
|---|---|---|---|
| メイン | M1〜M12 | 指定席（各 500 席 = 25 列 × 20 番） | 中央の M5〜M8 がカテゴリー1、それ以外がカテゴリー2 |
| バック | B1〜B12 | 指定席（各 500 席） | カテゴリー3 |
| 北（ホームゴール裏） | N1〜N16 | 自由席（各 250 人） | ゴール裏 |
| 南 | S1〜S16 | 自由席（各 250 人） | ゴール裏 |

指定席 12,000 席 + 自由席 8,000 人の計 20,000 人です（長居の球技場くらい）。
タッチライン側（メイン・バック）が 60%、ゴール裏が 40% になるようにしました。

<!-- TODO: 最初は 7,200 人でゴール裏の方が多く、端のゴール裏席の正面がメインスタンドだった、という作り直しの話を入れるか -->

<!-- TODO: モデルはパナスタと明言する（1-0 で決定）。実物との違い（規模を約半分、ゴール裏の構成）を一言 -->

## テーブルの全体像

```mermaid
erDiagram
    stadiums ||--o{ stands : has
    stadiums ||--o{ seat_categories : has
    stands ||--o{ blocks : has
    seat_categories ||--o{ blocks : priced_as
    blocks ||--o{ seats : "has (指定席のみ)"
    stadiums ||--o{ allocation_templates : has
    allocation_templates ||--o{ allocation_template_entries : has
    blocks ||--o{ allocation_template_entries : ""
    matches ||--o{ match_block_allocations : has
    blocks ||--o{ match_block_allocations : ""
```

大きく分けると 2 層です。

- **構造（マスタ）**: スタジアム → スタンド → ブロック → 席。試合が変わっても変わらない
- **割当（試合ごと）**: どのブロックをホームに売り、どこをアウェーに売り、どこを空けるか。試合ごとに変わる

## 判断 1: 指定席と自由席を同じ blocks テーブルで扱う

指定席と自由席は、売り方がまったく違います。

- 指定席は「M4 ブロック C 列 12 番」のように、1 席ずつ売る
- 自由席は「S1 ブロックに入れる人」を、定員まで売る

それでも、どちらも「スタンドの中の 1 区画」で、席種も割当もブロック単位で決まります。
そこで blocks テーブルは共通にして、`seating_type`（`reserved` / `unreserved`）と `capacity` を持たせました。
1 席ずつの `seats` 行を作るのは指定席だけです。

```php
Schema::create('blocks', function (Blueprint $table) {
    $table->id();
    $table->foreignId('stand_id')->constrained()->cascadeOnDelete();
    $table->foreignId('seat_category_id')->constrained()->cascadeOnDelete();
    $table->string('code')->unique();      // M1, S2 など
    $table->string('seating_type');        // reserved / unreserved
    $table->integer('capacity');           // 指定席 500、自由席 250
    $table->jsonb('geometry');             // 座席図での位置 {x, y, w, h}
    $table->timestamps();
});

Schema::create('seats', function (Blueprint $table) {
    $table->id();
    $table->foreignId('block_id')->constrained()->cascadeOnDelete();
    $table->string('row_label');           // A〜Y
    $table->integer('number');             // 1〜20
    $table->timestamps();
    $table->unique(['block_id', 'row_label', 'number']);
});
```

この違いは、二重販売の防ぎ方にもそのまま効いてきます。
指定席は「同じ試合の同じ席のチケットは 1 枚まで」をユニーク制約で守れますが、自由席は席がないので、定員を数える同時実行制御が必要になります。
これは購入フローの回で扱います。

### 座席図もデータから描く

ブロックには `geometry`（座席図上の位置とサイズ）を JSONB で持たせ、フロントはそれを SVG に並べるだけにしました。
スタジアムの形をコードに書かないので、ブロックを増やしたり配置を変えたりしても、フロントを触らずに済みます。

<!-- TODO: 座席図のスクリーンショット -->

## 判断 2: 割当は「試合 × ブロック」の属性にする

サッカーのチケットでは、ブロックごとに次のどれかを決めます。

| 割当 | 意味 |
|---|---|
| `home` | ホームのサポーターに売る |
| `away` | アウェーのサポーターに売る |
| `buffer` | 緩衝帯。両者の間を空けるために売らない |
| `closed` | 販売しない |

これをブロックの列（`blocks.allocation`）にすると、全試合で同じ割当になってしまいます。
実際には、人気のある相手との試合ではアウェー席を広げ、緩衝帯をずらします。
そこで割当は、試合とブロックの組ごとに 1 行を持つ `match_block_allocations` に置きました。

```php
Schema::create('match_block_allocations', function (Blueprint $table) {
    $table->id();
    $table->foreignId('match_id')->constrained()->cascadeOnDelete();
    $table->foreignId('block_id')->constrained()->cascadeOnDelete();
    $table->string('allocation');          // home / away / buffer / closed
    $table->timestamps();
    $table->unique(['match_id', 'block_id']);
});
```

## 判断 3: テンプレートは「コピー」で展開する

試合ごとに 56 ブロックの割当を毎回手で決めるのは大変なので、よく使う配置をテンプレートとして持ちます。
今は 2 つです。

| テンプレート | アウェー | 緩衝帯 | それ以外 |
|---|---|---|---|
| 通常配置 | S1〜S4（1,000 人） | S5（250 人） | home |
| 大規模アウェー配置 | S1〜S8（2,000 人） | S9（250 人） | home |

ゴール裏のブロックを 250 人と細かくしているのは、緩衝帯で空ける席を減らすためです。
ブロック単位でしか緩衝帯を置けないので、ブロックが大きいと、売れたはずの席を毎試合まとめて空けることになります。

<!-- TODO: 緩衝帯の機会損失の分析と、可変の境界は別の回で -->

ここで、テンプレートを試合にどう効かせるかの選択肢が 2 つあります。

| 方式 | 中身 | 良い点 | 悪い点 |
|---|---|---|---|
| **コピー** | 試合を作るときに、テンプレートの全行を `match_block_allocations` へ複製する | 試合ごとに自由に変えられる。当時の配置がそのまま残る。読むときは 1 テーブルを見るだけ | 行数が「試合数 × ブロック数」になる。テンプレートを直しても既存の試合には反映されない |
| 参照 + 上書き | 試合はテンプレートの ID だけ持ち、差分だけを別テーブルに書く | 行数が少ない。テンプレートの修正が全試合に効く | 読むたびに合成が要る。テンプレートを直すと、過去の試合の配置まで変わってしまう |

採用したのはコピー方式です。理由は 3 つあります。

1. **試合ごとの調整が主役だから**: 「この試合だけ緩衝帯を広げる」が日常的に起きる
2. **過去の配置が変わってはいけないから**: 売った後で「その時どこがアウェー席だったか」が変わると、販売の記録と食い違う。分析でも、当時の配置で集計したい
3. **行数は問題にならないから**: 1 試合 56 行。今のシードは 7 シーズン 123 試合で 6,888 行

「テンプレートの修正が既存の試合に効かない」のは、むしろ望ましい性質です。
すでに販売が始まっている試合の配置が、テンプレートの編集で勝手に変わるほうが事故になります。

展開の処理は、テンプレートの行をそのまま試合の行に写すだけです（今は試合をシードで作るので、シーダーの中にあります）。

```php
public function allocateMatch(FootballMatch $match, AllocationTemplate $normalTemplate, AllocationTemplate $bigAwayTemplate): void
{
    $isBigMatch = ($match->attributes['opponent_popularity'] ?? 1) >= 5;
    $template = $isBigMatch ? $bigAwayTemplate : $normalTemplate;

    $rows = [];
    foreach ($template->entries as $entry) {
        $rows[] = [
            'match_id' => $match->id,
            'block_id' => $entry->block_id,
            'allocation' => $entry->allocation,
            // ...
        ];
    }
    MatchBlockAllocation::insert($rows);
}
```

相手の人気度が 5 以上の試合（今のシードでは 123 試合中 14 試合）に、大規模アウェー配置を使います。

## 後から足したもの: CHECK 制約と変更履歴

最初は `allocation` をただの文字列にしていました。
シードでしか書かないうちはそれで困りませんでしたが、管理画面から割当を塗り替えられるようにした時点で、2 つ足しました。

- **CHECK 制約**: `allocation IN ('home','away','buffer','closed')`。アプリのバリデーションをすり抜けても、DB が受け付けない
- **変更履歴のテーブル**: 誰が、いつ、どのブロックを、何から何へ変えたか。1 回の保存の変更は同じ ID でまとめる

コピー方式では、試合の割当は書き換えると上書きされます。
「当時の配置が残る」は試合単位の話で、同じ試合の中での変更の経緯は、別に履歴を持たないと消えます。
管理画面の回で、購入とのロックの話と合わせて扱います。

<!-- TODO: seating_type には CHECK 制約がまだない。反省点として書くか、足してから書くか -->

## まとめ

- 構造（スタジアム → スタンド → ブロック → 席）はマスタ、割当は「試合 × ブロック」の属性に分けた
- 指定席と自由席は同じ blocks テーブルで、`seating_type` と `capacity` で区別した。席の行を作るのは指定席だけ
- 割当のテンプレートはコピーで展開した。試合ごとの調整と、過去の配置を保つことを優先した

次回は、この構造の上で座席図と購入フロー（仮押さえと二重販売の防止）を作った話です。

<!-- TODO: シリーズの他の回へのリンク -->
