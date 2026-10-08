<?php

namespace Tests\Unit\DemoData;

use App\Support\DemoData\DemoLeague;
use App\Support\DemoData\DemoLeagueBuilder;
use App\Support\DemoData\DemoScenario;
use App\Support\DemoData\LeagueSimulator;
use App\Support\DemoData\SeasonFormat;
use App\Support\DemoData\SeasonResult;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

class DemoLeagueTest extends TestCase
{
    private static ?DemoLeague $league = null;

    public static function league(): DemoLeague
    {
        return self::$league ??= (new DemoLeagueBuilder)->build();
    }

    public function test_team_pool_matches_scenario(): void
    {
        $teams = DemoScenario::teams();
        $this->assertCount(23, $teams);
        $this->assertTrue($teams[1]->isHomeClub);

        $pool = array_filter($teams, fn ($t) => ! $t->isHomeClub);
        $this->assertCount(22, $pool);
        $counts = array_count_values(array_map(fn ($t) => $t->popularity, $pool));
        ksort($counts);
        $this->assertSame([1 => 4, 2 => 5, 3 => 7, 4 => 4, 5 => 2], $counts);
        $this->assertCount(22, array_unique(array_map(fn ($t) => $t->name, $pool)));

        // 人気度 5 は強豪寄り（攻守とも平均より上）
        foreach ($pool as $team) {
            if ($team->popularity === 5) {
                $this->assertGreaterThan(1.1, $team->attack);
                $this->assertLessThan(0.9, $team->defense);
            }
        }
    }

    public function test_seeded_nine_teams_keep_their_seeder_names(): void
    {
        $names = array_map(fn ($t) => $t->name, array_slice(DemoScenario::teams(), 1, 9));
        $this->assertSame(['潮見グランディーズ', '弦巻ユナイテッド', '緑ヶ丘アスレチカ', '朱雀セレステ', '白鷺ヴィクトリア', '桜庭コメッツ', '鷹羽フェニックス', '津々浦フリート', '金剛レオネス'], $names);
    }

    public function test_has_seven_seasons_with_expected_sizes(): void
    {
        $league = self::league();
        $this->assertSame(['2021', '2022', '2023', '2024', '2025', '2026', '2026-27'], array_map(fn (SeasonResult $s) => $s->definition->name, $league->seasons));
        foreach ($league->seasons as $season) {
            $this->assertCount($season->definition->teamCount, $season->teamIds);
            $this->assertContains(DemoScenario::HOME_CLUB_ID, $season->teamIds);
        }
    }

    public function test_penusta_meets_each_season_condition(): void
    {
        $league = self::league();
        $simulator = new LeagueSimulator;

        $this->assertContains($league->season('2021')->penustaRank, [15, 16, 17]);
        foreach (['2022', '2023', '2024'] as $name) {
            $this->assertGreaterThanOrEqual(8, $league->season($name)->penustaRank, $name);
            $this->assertLessThanOrEqual(12, $league->season($name)->penustaRank, $name);
        }
        $this->assertSame(5, $league->season('2026')->penustaRank);
        $this->assertGreaterThanOrEqual(8, $league->season('2026-27')->penustaRank);
        $this->assertLessThanOrEqual(11, $league->season('2026-27')->penustaRank);

        $s2021 = $league->season('2021');
        $lowAfter25 = false;
        foreach ($simulator->roundTables($s2021->matches, $s2021->teamIds) as $round => $table) {
            foreach ($table as $row) {
                if ($row->teamId === 1 && $round >= 25 && $row->rank >= 16) {
                    $lowAfter25 = true;
                }
            }
        }
        $this->assertTrue($lowAfter25);

        $s2025 = $league->season('2025');
        $this->assertSame(2, $s2025->penustaRank);
        $this->assertLessThanOrEqual(3, $s2025->table[0]->points - $s2025->table[1]->points);
        $led = false;
        foreach ($simulator->roundTables($s2025->matches, $s2025->teamIds) as $round => $table) {
            $led = $led || ($round >= 30 && $table[0]->teamId === 1);
        }
        $this->assertTrue($led);
    }

    public function test_current_season_has_results_only_before_as_of(): void
    {
        $season = self::league()->season('2026-27');
        $asOf = DemoScenario::asOf();
        $played = 0;
        foreach ($season->matches as $match) {
            $this->assertSame($match->fixture->kickoffUtc < $asOf, $match->isPlayed());
            $played += $match->isPlayed() ? 1 : 0;
        }
        $this->assertGreaterThan(0, $played);
        $this->assertLessThan(count($season->matches), $played);
        $this->assertSame($played * 2, array_sum(array_map(fn ($r) => $r->played, $season->table)));
    }

    public function test_relegation_and_promotion(): void
    {
        $league = self::league();
        $teams = DemoScenario::teams();
        $members = $league->season('2021')->teamIds;
        $this->assertCount(20, $members);

        foreach ($league->seasons as $season) {
            $this->assertSame($members, $season->definition->format === SeasonFormat::Half ? $members : $season->teamIds, $season->definition->name);
            if ($season->definition->format !== SeasonFormat::SpringAutumn) {
                $this->assertSame([], $season->relegated);

                continue;
            }
            $this->assertSame(array_map(fn ($r) => $r->teamId, array_slice($season->table, -3)), $season->relegated);
            $this->assertCount(3, $season->promoted);
            $this->assertEmpty(array_intersect($season->promoted, $season->teamIds));
            foreach ($season->relegated as $id) {
                $this->assertLessThan(4, $teams[$id]->popularity);
            }
            $members = array_values(array_diff($members, $season->relegated));
            array_push($members, ...$season->promoted);
            sort($members);
            $this->assertNotContains(1, $season->relegated);
        }

        // 2025 終了時の入れ替え後の構成が 2026-27 になり、ハーフはその中の 9 チーム + ペヌスタ
        $this->assertSame($members, $league->season('2026-27')->teamIds);
        $this->assertEmpty(array_diff($league->season('2026')->teamIds, $members));
    }

    public function test_changing_as_of_only_rebuilds_the_current_season(): void
    {
        $earlier = new DateTimeImmutable('2026-09-20 00:00:00', new DateTimeZone('Asia/Tokyo'));
        $other = (new DemoLeagueBuilder(asOf: $earlier))->build();
        $dump = fn (SeasonResult $s) => [$s->attempts, array_map(fn ($m) => [$m->fixture->homeId, $m->fixture->awayId, $m->homeGoals, $m->awayGoals], $s->matches), $s->relegated, $s->promoted];

        foreach (['2021', '2022', '2023', '2024', '2025', '2026'] as $name) {
            $this->assertSame($dump(self::league()->season($name)), $dump($other->season($name)), $name);
        }
        $played = fn (SeasonResult $s) => count(array_filter($s->matches, fn ($m) => $m->isPlayed()));
        $this->assertLessThan($played(self::league()->season('2026-27')), $played($other->season('2026-27')));
    }

    public function test_same_base_seed_gives_identical_results_and_other_seed_differs(): void
    {
        $summary = fn (DemoLeague $l) => array_map(
            fn (SeasonResult $s) => [
                $s->attempts,
                array_map(fn ($m) => [$m->fixture->kickoffUtc->format('c'), $m->fixture->homeId, $m->fixture->awayId, $m->homeGoals, $m->awayGoals], $s->matches),
                $s->relegated,
                $s->promoted,
            ],
            $l->seasons,
        );

        $again = (new DemoLeagueBuilder)->build();
        $this->assertSame($summary(self::league()), $summary($again));

        $other = (new DemoLeagueBuilder)->build(12345);
        $this->assertNotSame($summary(self::league()), $summary($other));
    }
}
