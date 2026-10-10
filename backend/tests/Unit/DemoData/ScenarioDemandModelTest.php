<?php

namespace Tests\Unit\DemoData;

use App\Support\DemoData\BlockInfo;
use App\Support\DemoData\DemoScenario;
use App\Support\DemoData\MatchContext;
use App\Support\DemoData\MatchStanding;
use App\Support\DemoData\ScenarioDemandModel;
use App\Support\DemoData\SeasonSpan;
use App\Support\DemoData\SeededRandom;
use PHPUnit\Framework\TestCase;

class ScenarioDemandModelTest extends TestCase
{
    private ScenarioDemandModel $model;

    protected function setUp(): void
    {
        $this->model = new ScenarioDemandModel;
    }

    /** @param  array<string, mixed>  $attributes */
    private function match(array $attributes = [], string $season = '2023', ?MatchStanding $standing = null, float $priceRatio = 1.0, bool $first = false, bool $last = false, string $kickoff = '2023-06-10 14:00'): MatchContext
    {
        $definition = collect(DemoScenario::seasons())->first(fn ($d) => $d->name === $season);
        $attributes += ['opponent_popularity' => 3, 'weather' => '晴', 'temperature' => 20.0, 'is_holiday' => false, 'round_ratio' => 0.5];
        $kickoffAt = (new \DateTimeImmutable($kickoff, new \DateTimeZone('Asia/Tokyo')))->getTimestamp();

        return new MatchContext(1, $kickoffAt, $attributes, new SeasonSpan(0, $definition, 1, '2023-02-01', '2023-12-01'), $standing, [10 => $priceRatio], $first, $last);
    }

    private function block(string $type): BlockInfo
    {
        return new BlockInfo(1, 'X1', 10, $type, 'reserved', 500);
    }

    public function test_index_is_monotonic_in_level_opponent_and_price(): void
    {
        $levels = array_map(fn (string $season) => $this->model->index($this->match(season: $season), 1.0, 1.0), ['2022', '2023', '2024']);
        $this->assertSame($levels, array_values(collect($levels)->sort()->all()));

        $opponents = array_map(fn (int $p) => $this->model->index($this->match(['opponent_popularity' => $p]), 1.0, 1.0), [1, 2, 3, 4, 5]);
        $this->assertSame($opponents, array_values(collect($opponents)->sort()->all()));
        $this->assertEqualsWithDelta(1.25 / 0.85, $opponents[4] / $opponents[0], 1e-9);

        $prices = array_map(fn (float $r) => $this->model->index($this->match(), $r, 1.0), [0.8, 0.9, 1.0, 1.1, 1.2]);
        $this->assertSame($prices, array_values(collect($prices)->sortDesc()->all()));
        $this->assertEqualsWithDelta(0.85 ** -0.8, $this->model->index($this->match(), 0.85, 1.0) / $this->model->index($this->match(), 1.0, 1.0), 1e-9);
    }

    public function test_block_demand_uses_the_category_price_ratio(): void
    {
        $cheap = $this->model->blockDemand($this->match(priceRatio: 0.85), $this->block('main'), 'home', 1.0);
        $base = $this->model->blockDemand($this->match(), $this->block('main'), 'home', 1.0);

        $this->assertGreaterThan($base->rate, $cheap->rate);
        $this->assertGreaterThan($base->index, $cheap->index);
    }

    public function test_race_boundaries(): void
    {
        $title = fn (float $p, int $rank = 2, int $points = 50, int $leader = 54) => $this->model->race($this->match(['round_ratio' => $p], standing: new MatchStanding($rank, $points, 'WWDLW', $leader, 30)));

        $this->assertSame(1.0, $title(0.65));
        $this->assertEqualsWithDelta(1.0, $title(0.66), 1e-9);
        $this->assertEqualsWithDelta(1.0 + ScenarioDemandModel::RACE_TITLE_BONUS, $title(1.0), 1e-9);
        // 首位との差が 7 ならタイトル争いではない（中位の終盤は 0.93）
        $this->assertSame(0.93, $title(0.8, rank: 3, points: 47, leader: 54));
        // 4 位ならタイトル争いではない
        $this->assertSame(0.93, $title(0.8, rank: 4, points: 52, leader: 54));
    }

    public function test_race_survival_fight_and_late_slump(): void
    {
        $fight = fn (float $p, int $rank, int $points, ?int $cutoff = 30) => $this->model->race($this->match(['round_ratio' => $p], standing: new MatchStanding($rank, $points, 'LLDLL', 60, $cutoff)));

        $this->assertEqualsWithDelta(1.0 + ScenarioDemandModel::RACE_SURVIVAL_BONUS, $fight(1.0, 15, 35), 1e-9);
        // 降格圏（18 位）との勝点差 6 以内は 10 位でも残留争い
        $this->assertGreaterThan(1.0, $fight(0.9, 10, 36));
        $this->assertSame(0.93, $fight(0.9, 10, 37));
        // 序盤は何位でも 1
        $this->assertSame(1.0, $fight(0.5, 18, 10));
        $this->assertSame(1.0, $fight(0.78, 10, 45));
        $this->assertSame(0.93, $fight(0.79, 10, 45));
        // ハーフ（cutoff なし）は残留争いにならない
        $this->assertSame(0.93, $fight(1.0, 9, 5, null));
        // 順位表が無い（第 1 節）
        $this->assertSame(1.0, $this->model->race($this->match(['round_ratio' => 0.9])));
    }

    public function test_form_and_day_and_weather(): void
    {
        $this->assertSame(1.0, $this->model->form($this->match()));
        $form = fn (string $f) => $this->model->form($this->match(standing: new MatchStanding(5, 10, $f, 20, 5)));
        $this->assertEqualsWithDelta(1.0 + 0.03 * 8, $form('WWWWW'), 1e-9);
        $this->assertEqualsWithDelta(1.0 - 0.03 * 7, $form('LLLLL'), 1e-9);
        $this->assertEqualsWithDelta(1.0, $form('WWDLL'), 1e-9);

        $this->assertSame(1.0, $this->model->day($this->match()));
        $this->assertSame(0.98, $this->model->day($this->match(kickoff: '2023-06-10 18:00')));
        $this->assertSame(1.0, $this->model->day($this->match(kickoff: '2023-06-11 18:00')));
        $this->assertSame(0.75, $this->model->day($this->match(kickoff: '2023-06-07 19:30')));
        $this->assertSame(1.08, $this->model->day($this->match(['is_holiday' => true], kickoff: '2023-06-07 14:00')));
        $this->assertEqualsWithDelta(1.15, $this->model->day($this->match(first: true)), 1e-9);
        $this->assertEqualsWithDelta(1.10, $this->model->day($this->match(last: true)), 1e-9);

        $this->assertSame(0.98, $this->model->weather($this->match(['weather' => '曇'])));
        $this->assertSame(1.0, $this->model->weather($this->match(['weather' => '雨'])));
        $this->assertSame(0.95, $this->model->weather($this->match(['temperature' => 30.1])));
        $this->assertSame(0.93, $this->model->weather($this->match(['temperature' => 7.9])));
    }

    public function test_main_is_more_sensitive_than_goal_back(): void
    {
        $change = fn (string $type) => ScenarioDemandModel::saleRate($type, 1.0) / ScenarioDemandModel::saleRate($type, 0.7);

        $this->assertGreaterThan($change('goal_back'), $change('main'));
        $this->assertGreaterThan($change('goal_back'), $change('general'));
        $this->assertEqualsWithDelta(0.90 * 0.7 ** 0.6, ScenarioDemandModel::saleRate('goal_back', 0.7), 1e-9);
        $this->assertEqualsWithDelta(0.70 * 0.7 ** 1.3, ScenarioDemandModel::saleRate('main', 0.7), 1e-9);
        $this->assertEqualsWithDelta(0.75 * 0.7, ScenarioDemandModel::saleRate('general', 0.7), 1e-9);
    }

    public function test_away_block_depends_on_opponent_popularity_only(): void
    {
        $away = fn (int $p, string $season = '2023') => $this->model->blockDemand($this->match(['opponent_popularity' => $p], season: $season), $this->block('goal_back'), 'away', 1.0)->rate;

        $this->assertEqualsWithDelta(0.20 + 0.12 * 3, $away(3), 1e-9);
        $this->assertEqualsWithDelta($away(3), $away(3, '2025'), 1e-9);
        $this->assertGreaterThan($away(2), $away(5));
    }

    public function test_lead_time_is_longer_for_popular_matches(): void
    {
        $mean = function (float $index) {
            $rng = new SeededRandom(7, 'lead');
            $sum = 0;
            for ($i = 0; $i < 4000; $i++) {
                $sum += $this->model->leadMinutes($index, $rng);
            }

            return $sum / 4000 / 1440;
        };

        $this->assertGreaterThan($mean(0.5) * 1.2, $mean(1.3));
        $this->assertEqualsWithDelta($mean(1.3), $mean(2.0), 0.0);
    }
}
