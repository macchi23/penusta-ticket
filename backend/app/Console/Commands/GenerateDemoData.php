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
use App\Support\DemoData\MembershipPlan;
use App\Support\DemoData\MembershipPlanner;
use App\Support\DemoData\PgCopy;
use App\Support\DemoData\SeasonCrowd;
use App\Support\DemoData\SeasonSpan;
use Database\Seeders\MatchSeeder;
use Illuminate\Console\Command;
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

    public function handle(DemandModel $demand): int
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

        foreach (array_slice($spans, -$seasonCount) as $span) {
            $crowd = new SeasonCrowd($population, $plan, $span, $idOffset);
            $generator = new MatchSalesGenerator($demand, $crowd, $sink, $ticketTypeIds, $seed, $browse, $scale);
            $matches = 0;

            foreach (FootballMatch::where('season_id', $span->seasonId)->orderBy('kickoff_at')->orderBy('id')->get() as $match) {
                if ($this->isUnsold($match)) {
                    continue;
                }
                $generator->generate(
                    new MatchContext($match->id, $match->kickoff_at->getTimestamp(), $match->attributes ?? [], $span),
                    $this->allocatedBlocks($match->id, $blocks),
                    $seatIds,
                    $this->loadPrices($match->id),
                );
                $matches++;
            }
            $sink->flush();
            $this->line(sprintf('  %s: %d 試合', $span->definition->name, $matches));
        }

        $this->finish();

        $this->info(sprintf(
            '完了しました（%.1f 秒）。users %d / memberships %d / orders %d / tickets %d / events %d',
            microtime(true) - $started,
            $sink->count('users'),
            $sink->count('memberships'),
            $sink->count('orders'),
            $sink->count('tickets'),
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
        DB::statement('TRUNCATE events, order_items, tickets, orders, memberships RESTART IDENTITY');
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
        foreach (['users', 'orders', 'tickets'] as $table) {
            $this->resetSequence($table);
        }
        DB::statement('ANALYZE users, memberships, orders, tickets, order_items, events');
    }

    /** @return list<SeasonSpan> 古い順 */
    private function loadSpans(): array
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
