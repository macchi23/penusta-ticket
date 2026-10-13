<?php

namespace App\Console\Commands;

use App\Models\Block;
use App\Models\FootballMatch;
use App\Models\Season;
use App\Models\TicketType;
use App\Support\DemoData\BlockInfo;
use App\Support\DemoData\CopySink;
use App\Support\DemoData\DemandModel;
use App\Support\DemoData\DemoPopulation;
use App\Support\DemoData\DemoPopulationGenerator;
use App\Support\DemoData\DemoScenario;
use App\Support\DemoData\MatchContext;
use App\Support\DemoData\MatchSalesGenerator;
use App\Support\DemoData\MatchStanding;
use App\Support\DemoData\MembershipPlan;
use App\Support\DemoData\MembershipPlanner;
use App\Support\DemoData\PgCopy;
use App\Support\DemoData\SalePhaseWindow;
use App\Support\DemoData\SeasonCrowd;
use App\Support\DemoData\SeasonFormat;
use App\Support\DemoData\SeasonPassSeating;
use App\Support\DemoData\SeasonSpan;
use App\Support\DemoData\SeededRandom;
use Database\Seeders\MatchSeeder;
use Database\Seeders\PriceSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class GenerateDemoData extends Command
{
    protected $signature = 'penusta:generate-demo-data
        {--seasons=1 : 2026-27 から遡って取引データを作るシーズン数(1〜7)}
        {--scale=1.0 : ユーザー数と注文数の倍率}
        {--seed=42 : 乱数の基本シード}
        {--browse=3.0 : 1 購入あたりの離脱セッション数}';

    protected $description = 'ペヌスタチケット販売サイトの合成デモデータ（ユーザー・会員・注文・チケット・行動ログ）を生成する';

    /** scale × seasons がこれ以上で USE_ZEND_ALLOC=0 が無ければ警告する */
    private const ZEND_ALLOC_WARN_THRESHOLD = 3.0;

    /** 'password' の bcrypt（cost 10）。実行ごとに salt が変わると再現できないので固定する */
    private const PASSWORD_HASH = '$2y$10$p0EzVT2toiKXvziRGqVK8ekckTurz5VXkkYY3nCQZ9CMcM.8FSxnG';

    private ?int $adultTypeId = null;

    public function handle(DemandModel $demand): int
    {
        // 循環参照を作らない処理で、行や出品の配列・オブジェクトを大量に作り捨てる。GC を走らせても回収できず、
        // 回収が空振りするたびに閾値が上がって、同じプロセスの後続の処理（テスト）に未回収のゴミが溜まるので止めておく。
        // 先に溜まっているゴミ（前のテストのアプリ等）は止める前に回収する
        gc_collect_cycles();
        gc_disable();
        try {
            return $this->generateAll($demand);
        } finally {
            gc_enable();
        }
    }

    private function generateAll(DemandModel $demand): int
    {
        $seasonCount = (int) $this->option('seasons');
        $scale = (float) $this->option('scale');
        $seed = (int) $this->option('seed');
        $browse = (float) $this->option('browse');

        if ($seasonCount < 1 || $seasonCount > 7 || $scale <= 0.0 || $browse < 0.0) {
            $this->error('--seasons は 1〜7、--scale は正の数、--browse は 0 以上にする。');

            return self::FAILURE;
        }
        if ($scale * $seasonCount >= self::ZEND_ALLOC_WARN_THRESHOLD && getenv('USE_ZEND_ALLOC') !== '0') {
            $this->warn('規模が大きい。USE_ZEND_ALLOC=0 を付けて実行すること（付けないと zend_mm_heap corrupted が出ることがある）。');
        }

        $spans = $this->loadSpans();
        $started = microtime(true);

        $this->info('既存のデモデータをクリーンアップ中...');
        $this->cleanup();
        $idOffset = (int) DB::table('users')->max('id');

        $sink = new CopySink(DB::connection()->getPdo());

        $this->info('ユーザーを生成中...');
        $population = $this->generateUsers($seed, $scale, $idOffset, $sink);

        $this->info('会員を生成中...');
        $plan = (new MembershipPlanner($seed, $scale))->plan($population, $spans);
        $this->writeMemberships($plan, $spans, $population, $idOffset, $sink);
        $sink->flush();
        $this->reportMemberships($plan, $spans);

        $this->info('試合ごとの注文・チケット・行動ログを生成中...');
        $ticketTypeIds = TicketType::pluck('id', 'code')->all();
        $blocks = $this->loadBlocks();
        $seatIds = $this->loadSeatIds();
        $alwaysHomeBlockIds = $this->alwaysHomeBlockIds();
        $categoryBasePrices = $this->categoryBasePrices();

        foreach (array_slice($spans, -$seasonCount) as $span) {
            $crowd = new SeasonCrowd($population, $plan, $span, $idOffset);
            $seating = new SeasonPassSeating(
                $crowd->seasonPassHolders(),
                $blocks,
                $seatIds,
                $alwaysHomeBlockIds,
                new SeededRandom($seed, 'passseat:'.$span->definition->name),
            );
            $generator = new MatchSalesGenerator($demand, $crowd, $seating, $sink, $ticketTypeIds, $seed, $browse, $scale);
            $standings = $this->loadStandings($span);
            $seasonMatches = FootballMatch::where('season_id', $span->seasonId)->orderBy('kickoff_at')->orderBy('id')->get();
            $firstId = $seasonMatches->first()?->id;
            $lastId = $seasonMatches->last()?->id;
            $phases = $this->loadPhases($seasonMatches->pluck('id')->all());
            $matches = 0;

            $generator->writePassSeats($span);
            foreach ($seasonMatches as $match) {
                $prices = $this->loadPrices($match->id);
                $attributes = $match->attributes ?? [];
                $context = new MatchContext(
                    $match->id,
                    $match->kickoff_at->getTimestamp(),
                    $attributes,
                    $span,
                    $this->standingBefore($standings, (int) ($attributes['round'] ?? 0)),
                    $this->priceRatios($prices, $categoryBasePrices),
                    $match->id === $firstId,
                    $match->id === $lastId,
                    $phases[$match->id] ?? [],
                );
                // 年パスの固定席は未販売の試合にも発行する（アプリの SeasonPassIssuer が全試合に発行するのと同じ）
                $generator->issuePasses($context);
                if (! $this->isUnsold($match)) {
                    $generator->generate(
                        $context,
                        $this->allocatedBlocks($match->id, $blocks),
                        $seatIds,
                        $prices,
                    );
                    $matches++;
                }
                // チケットと出品は試合の終わりにまとめて書く（リセールの成約で書き換わるため）
                $generator->commitMatch();
            }
            $sink->flush();
            $resale = $generator->stats();
            $this->line(sprintf(
                '  %s: %d 試合（リセール 出品 %d（うち値下げの再出品 %d） / 成約 %d、うち一次から流れた %d / あふれの吸収 %d）',
                $span->definition->name, $matches, $resale['listed'], $resale['relisted'], $resale['sold'], $resale['cannibalized'], $resale['absorbed'],
            ));
        }

        $this->finish();

        $this->info(sprintf(
            '完了しました（%.1f 秒）。users %d / memberships %d / season_pass_seats %d / orders %d / tickets %d / resale_listings %d / events %d',
            microtime(true) - $started,
            $sink->count('users'),
            $sink->count('memberships'),
            $sink->count('season_pass_seats'),
            $sink->count('orders'),
            $sink->count('tickets'),
            $sink->count('resale_listings'),
            $sink->count('events'),
        ));

        return self::SUCCESS;
    }

    public function isUnsold(FootballMatch $match): bool
    {
        return ($match->attributes[MatchSeeder::UNSOLD_KEY] ?? false) === true;
    }

    /**
     * 取引系テーブルを空にして ID を振り直す。users は test@example.com と admin を残し、その最大 id から連番を振り直す
     */
    private function cleanup(): void
    {
        DB::statement('TRUNCATE events, order_items, resale_holds, resale_listings, tickets, season_pass_seats, orders, memberships RESTART IDENTITY');
        DB::table('users')->where('email', '!=', 'test@example.com')->where('role', '!=', 'admin')->delete();
        $this->resetSequence('users');
    }

    /** 明示 id で入れたテーブルの採番を、最大 id の次に合わせる */
    private function resetSequence(string $table): void
    {
        DB::statement("SELECT setval(pg_get_serial_sequence('{$table}', 'id'), COALESCE((SELECT max(id) FROM {$table}), 1), (SELECT max(id) IS NOT NULL FROM {$table}))");
    }

    private function finish(): void
    {
        foreach (['users', 'orders', 'tickets', 'season_pass_seats', 'resale_listings', 'resale_holds'] as $table) {
            $this->resetSequence($table);
        }
        DB::statement('ANALYZE users, memberships, season_pass_seats, orders, tickets, order_items, resale_listings, resale_holds, events');
    }

    /** @return list<SeasonSpan> 古い順 */
    public function loadSpans(): array
    {
        $seasons = Season::all()->keyBy('name');
        $spans = [];
        foreach (DemoScenario::seasons() as $index => $definition) {
            $name = $definition->format->value === 'half' ? $definition->name.' ハーフ' : $definition->name;
            $season = $seasons->get($name) ?? throw new \RuntimeException("シーズン {$name} が無い。先に db:seed を実行すること。");
            $spans[] = new SeasonSpan($index, $definition, $season->id, $season->starts_on->toDateString(), $season->ends_on->toDateString());
        }

        return $spans;
    }

    private function generateUsers(int $seed, float $scale, int $idOffset, CopySink $sink): DemoPopulation
    {
        $count = DemoPopulationGenerator::countFor($scale);

        $population = (new DemoPopulationGenerator($seed))->generate($count, function (int $index, array $user) use ($sink, $idOffset) {
            $joined = PgCopy::timestamp($user['joined_at']);
            $sink->add('users', [
                $idOffset + $index + 1, $user['name'], $user['email'], $joined, self::PASSWORD_HASH, $user['birth_date'],
                $user['gender'], $user['prefecture'], $user['attributes'], $joined, $joined,
            ]);
            $sink->checkpoint();
        });
        $sink->flush();

        return $population;
    }

    /** @param  list<SeasonSpan>  $spans */
    private function writeMemberships(MembershipPlan $plan, array $spans, DemoPopulation $population, int $idOffset, CopySink $sink): void
    {
        foreach ($spans as $span) {
            foreach (MembershipPlan::KINDS as $kind) {
                foreach ($plan->holders($kind, $span->index) as $index => $validFrom) {
                    // 作成日時は入会時刻より前にしない
                    $created = PgCopy::timestamp(max(MembershipPlan::validFromEpoch($validFrom), $population->joinedAt[$index]));
                    $sink->add('memberships', [$idOffset + $index + 1, $kind, $validFrom, $span->endsOn, $created, $created]);
                }
            }
        }
    }

    /** @param  list<SeasonSpan>  $spans */
    private function reportMemberships(MembershipPlan $plan, array $spans): void
    {
        foreach ($spans as $span) {
            $line = [];
            foreach (MembershipPlan::KINDS as $kind) {
                $stats = $plan->stats($kind, $span->index);
                $line[] = sprintf('%s %d（更新 %d / %d、新規 %d）', $kind, $plan->count($kind, $span->index), $stats['renewed'], $stats['previous'], $stats['added']);
            }
            $this->line(sprintf('  %s: %s', $span->definition->name, implode(' / ', $line)));
        }
    }

    /** @return array<int, BlockInfo> */
    private function loadBlocks(): array
    {
        $blocks = [];
        foreach (Block::with('seatCategory')->orderBy('id')->get() as $block) {
            $name = $block->seatCategory->name;
            $blocks[$block->id] = new BlockInfo(
                $block->id,
                $block->code,
                $block->seat_category_id,
                match (true) {
                    $name === 'ゴール裏' => 'goal_back',
                    in_array($name, ['カテゴリー1', 'カテゴリー2'], true) => 'main',
                    default => 'general',
                },
                $block->seating_type,
                $block->capacity,
            );
        }

        return $blocks;
    }

    /** @return array<int, list<int>> block id => 座席 id（昇順） */
    private function loadSeatIds(): array
    {
        $seatIds = [];
        foreach (DB::table('seats')->orderBy('id')->get(['id', 'block_id']) as $seat) {
            $seatIds[$seat->block_id][] = $seat->id;
        }

        return $seatIds;
    }

    /**
     * home / away の割当があるブロック（buffer / closed には売らない）
     *
     * @param  array<int, BlockInfo>  $blocks
     * @return list<array{block: BlockInfo, allocation: string}>
     */
    private function allocatedBlocks(int $matchId, array $blocks): array
    {
        $rows = [];
        $allocations = DB::table('match_block_allocations')->where('match_id', $matchId)->orderBy('block_id')->get(['block_id', 'allocation']);
        foreach ($allocations as $row) {
            if (in_array($row->allocation, ['home', 'away'], true) && isset($blocks[$row->block_id])) {
                $rows[] = ['block' => $blocks[$row->block_id], 'allocation' => $row->allocation];
            }
        }

        return $rows;
    }

    /**
     * 全試合で home の割当のブロック（年パスの固定席を置ける）
     *
     * @return list<int>
     */
    private function alwaysHomeBlockIds(): array
    {
        return DB::table('match_block_allocations')->groupBy('block_id')
            ->havingRaw("count(*) filter (where allocation <> 'home') = 0")
            ->orderBy('block_id')->pluck('block_id')->map(fn ($id) => (int) $id)->all();
    }

    /** @return array<int, float> seat_category_id => 2025 の基準の大人価格 */
    private function categoryBasePrices(): array
    {
        $prices = [];
        foreach (DB::table('seat_categories')->get(['id', 'name']) as $category) {
            if (isset(PriceSeeder::BASE_PRICES[$category->name])) {
                $prices[$category->id] = (float) PriceSeeder::BASE_PRICES[$category->name][0];
            }
        }

        return $prices;
    }

    /**
     * @param  array<int, array<int, int>>  $prices  seat_category_id => ticket_type_id => 価格
     * @param  array<int, float>  $basePrices
     * @return array<int, float>
     */
    private function priceRatios(array $prices, array $basePrices): array
    {
        $adultId = $this->adultTypeId ??= (int) TicketType::where('code', 'adult')->value('id');
        $ratios = [];
        foreach ($prices as $categoryId => $byType) {
            if (isset($byType[$adultId], $basePrices[$categoryId])) {
                $ratios[$categoryId] = $byType[$adultId] / $basePrices[$categoryId];
            }
        }

        return $ratios;
    }

    /**
     * ペヌスタの節ごとの順位表（節 => 抜粋）。結果のある節だけ
     *
     * @return array<int, MatchStanding>
     */
    public function loadStandings(SeasonSpan $span): array
    {
        $rows = DB::table('league_standings')->where('season_id', $span->seasonId)->orderBy('round')->orderBy('position')->get();
        $isHalf = $span->definition->format === SeasonFormat::Half;
        $cutoffRank = $span->definition->teamCount - DemoScenario::RELEGATION_SLOTS + 1;

        $byRound = [];
        foreach ($rows as $row) {
            $byRound[$row->round][$row->position] = $row;
        }

        $standings = [];
        foreach ($byRound as $round => $table) {
            foreach ($table as $row) {
                if ($row->team_id === DemoScenario::HOME_CLUB_ID) {
                    $standings[$round] = new MatchStanding(
                        $row->position,
                        $row->points,
                        $row->form,
                        $table[1]->points,
                        $isHalf ? null : ($table[$cutoffRank]->points ?? null),
                    );
                }
            }
        }

        return $standings;
    }

    /**
     * 第 $round 節の試合の前の順位表。直前の節の分が無ければ（先の節）結果のある最後の節の分を使う。第 1 節は null
     *
     * @param  array<int, MatchStanding>  $standings
     */
    public function standingBefore(array $standings, int $round): ?MatchStanding
    {
        for ($r = $round - 1; $r >= 1; $r--) {
            if (isset($standings[$r])) {
                return $standings[$r];
            }
        }

        return null;
    }

    /**
     * 試合の販売フェーズ（開始の昇順）。生成器はフェーズの期間と資格に注文時刻を合わせる
     *
     * @param  list<int>  $matchIds
     * @return array<int, list<SalePhaseWindow>> match id => フェーズ
     */
    private function loadPhases(array $matchIds): array
    {
        $phases = [];
        foreach (DB::table('sale_phases')->whereIn('match_id', $matchIds)->orderBy('starts_at')->orderBy('id')->get() as $row) {
            $phases[$row->match_id][] = new SalePhaseWindow(
                $row->id,
                Carbon::parse($row->starts_at)->getTimestamp(),
                Carbon::parse($row->ends_at)->getTimestamp(),
                $row->eligibility,
            );
        }

        return $phases;
    }

    /** @return array<int, array<int, int>> seat_category_id => ticket_type_id => 価格 */
    private function loadPrices(int $matchId): array
    {
        $prices = [];
        foreach (DB::table('match_prices')->where('match_id', $matchId)->get(['seat_category_id', 'ticket_type_id', 'price']) as $row) {
            $prices[$row->seat_category_id][$row->ticket_type_id] = $row->price;
        }

        return $prices;
    }
}
