<?php

namespace Tests\Unit\DemoData;

use App\Support\DemoData\DemoPopulationGenerator;
use App\Support\DemoData\DemoScenario;
use App\Support\DemoData\MembershipPlan;
use App\Support\DemoData\MembershipPlanner;
use App\Support\DemoData\SeasonSpan;
use PHPUnit\Framework\TestCase;

class MembershipPlannerTest extends TestCase
{
    public function test_renewal_rate_follows_previous_season_tier(): void
    {
        $spans = [];
        foreach (DemoScenario::seasons() as $i => $definition) {
            $spans[] = new SeasonSpan($i, $definition, $i + 1, $definition->startYear.'-03-01', ($definition->startYear + ($i === 6 ? 1 : 0)).'-11-30');
        }
        $population = (new DemoPopulationGenerator(42))->generate(DemoPopulationGenerator::countFor(0.5), fn () => null);

        $plan = (new MembershipPlanner(42, 0.5))->plan($population, $spans);

        // 前シーズンが 2021（残留争い）→ 2022、2022〜2024・ハーフ（中位）→ 次、2025（タイトル争い）→ 2026 ハーフ
        foreach (MembershipPlan::KINDS as $kind) {
            $rate = function (array $seasons) use ($plan, $kind) {
                $prev = $renewed = 0;
                foreach ($seasons as $s) {
                    $stats = $plan->stats($kind, $s);
                    $prev += $stats['previous'];
                    $renewed += $stats['renewed'];
                }

                return $renewed / $prev;
            };
            $relegation = $rate([1]);
            $mid = $rate([2, 3, 4, 6]);
            $title = $rate([5]);
            $this->assertGreaterThan($mid, $title, $kind);
            $this->assertGreaterThan($relegation, $mid, $kind);
        }
    }

    public function test_planning_is_deterministic(): void
    {
        $definition = DemoScenario::seasons()[0];
        $spans = [new SeasonSpan(0, $definition, 1, '2021-03-01', '2021-11-30')];
        $make = fn () => (new MembershipPlanner(7, 0.05))->plan(
            (new DemoPopulationGenerator(7))->generate(DemoPopulationGenerator::countFor(0.05), fn () => null),
            $spans,
        )->holders(MembershipPlan::FANCLUB, 0);

        $this->assertSame($make(), $make());
    }
}
