<?php

namespace Tests\Concerns;

use App\Enums\MembershipKind;
use App\Models\Block;
use App\Models\FootballMatch;
use App\Models\MatchBlockAllocation;
use App\Models\MatchPrice;
use App\Models\SalePhase;
use App\Models\Season;
use App\Models\SeatCategory;
use App\Models\Stadium;
use App\Models\Stand;
use App\Models\Team;
use App\Models\TicketType;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * 販売テスト共通の土台（スタジアム・試合・券種・価格・利用者）。使うクラスは下記のプロパティを宣言すること。
 * ブロックは各テストで makeBlock() を使って作る。
 *
 * @property FootballMatch $match
 * @property TicketType $adult
 * @property User $alice
 */
trait BuildsSaleFixture
{
    private Stand $stand;

    private SeatCategory $category;

    private function buildSaleFixture(): void
    {
        $stadium = Stadium::create(['name' => 'Test Stadium', 'short_name' => 'TS']);
        $this->stand = Stand::create(['stadium_id' => $stadium->id, 'name' => 'Main', 'code' => 'M']);
        $this->category = SeatCategory::create(['stadium_id' => $stadium->id, 'name' => 'S席', 'display_order' => 1]);
        $season = Season::create(['name' => 'Season 2026', 'year' => 2026]);
        $home = Team::create(['name' => 'H', 'short_name' => 'H', 'color' => '#000000', 'is_home_club' => true]);
        $away = Team::create(['name' => 'A', 'short_name' => 'A', 'color' => '#ffffff', 'is_home_club' => false]);

        $this->match = FootballMatch::create([
            'season_id' => $season->id, 'home_team_id' => $home->id, 'away_team_id' => $away->id,
            'stadium_id' => $stadium->id, 'kickoff_at' => CarbonImmutable::parse('2030-03-01 10:00:00', 'UTC'),
            'status' => 'on_sale', 'section_label' => 'A',
        ]);
        $this->makeSalePhases('general');
    }

    /**
     * 試合の販売フェーズを作り直す。$state: 'general'（一般販売中・既定）/ 'presale'（FC 先行中）/ 'before'（先行前）。
     * 基準は now（travelTo していればその時刻）。一般販売の終了は kickoff だが、
     * now が kickoff に近いときも区間が成り立つよう、開始 + 1 日を下限にする。
     */
    private function makeSalePhases(string $state = 'general'): void
    {
        $now = CarbonImmutable::now('UTC');
        $kickoff = CarbonImmutable::instance($this->match->kickoff_at);
        $this->match->salePhases()->delete();

        [$presaleStart, $generalStart] = match ($state) {
            'general' => [$now->subDays(10), $now->subDays(3)],
            'presale' => [$now->subDays(3), $now->addDays(4)],
            'before' => [$now->addDays(3), $now->addDays(10)],
        };

        $generalEnd = max($kickoff, $generalStart->addDay());

        foreach ([
            ['fanclub_presale', MembershipKind::Fanclub->value, $presaleStart, $generalStart],
            ['general', null, $generalStart, $generalEnd],
        ] as [$kind, $eligibility, $start, $end]) {
            SalePhase::create([
                'match_id' => $this->match->id, 'kind' => $kind, 'eligibility' => $eligibility,
                'starts_at' => $start, 'ends_at' => $end,
            ]);
        }
    }

    private function buildSalePricesAndUser(): void
    {
        $this->adult = TicketType::create(['name' => '大人', 'code' => 'adult', 'display_order' => 1]);
        MatchPrice::create(['match_id' => $this->match->id, 'seat_category_id' => $this->category->id,
            'ticket_type_id' => $this->adult->id, 'price' => 5000]);

        $this->alice = User::create(['name' => 'Alice', 'email' => 'a@example.com', 'password' => 'x']);
    }

    private function makeBlock(string $code, string $type, int $capacity): Block
    {
        $block = Block::create([
            'stand_id' => $this->stand->id, 'seat_category_id' => $this->category->id, 'code' => $code,
            'seating_type' => $type, 'capacity' => $capacity, 'geometry' => ['type' => 'rect'],
        ]);
        MatchBlockAllocation::create(['match_id' => $this->match->id, 'block_id' => $block->id, 'allocation' => 'home']);

        return $block;
    }
}
