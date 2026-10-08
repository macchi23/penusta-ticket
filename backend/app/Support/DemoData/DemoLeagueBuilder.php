<?php

namespace App\Support\DemoData;

use DateTimeImmutable;

/**
 * 7 シーズン分を、昇降格を挟みながら順に作る。
 * AS_OF を変えると 2026-27 の結果は作り直される（2025 以前と 2026 ハーフは不変）
 */
final class DemoLeagueBuilder
{
    public function __construct(
        private readonly FixtureScheduler $scheduler = new FixtureScheduler,
        private readonly LeagueSimulator $simulator = new LeagueSimulator,
        private readonly ?DateTimeImmutable $asOf = null,
    ) {}

    public function build(int $baseSeed = DemoScenario::DEFAULT_SEED): DemoLeague
    {
        $teams = DemoScenario::teams();
        $asOf = $this->asOf ?? DemoScenario::asOf();

        // 下部リーグの 3 チームは人気度 1 の中から選ぶ
        $lowPopularity = array_values(array_filter($teams, fn (DemoTeam $t) => $t->popularity === 1));
        $lowerIds = array_map(
            fn (DemoTeam $t) => $t->id,
            array_slice((new SeededRandom($baseSeed, 'initial-lower'))->shuffle($lowPopularity), 0, DemoScenario::LOWER_LEAGUE_SIZE),
        );
        $topIds = array_values(array_diff(array_keys($teams), $lowerIds));
        sort($topIds);

        $results = [];
        foreach (DemoScenario::seasons() as $season) {
            $seasonIds = $season->format === SeasonFormat::Half ? $this->halfGroup($topIds, $teams) : $topIds;
            $rounds = $this->scheduler->schedule($season, $seasonIds, DemoScenario::HOME_CLUB_ID, new SeededRandom($baseSeed, "fixture:{$season->name}"));
            $result = $this->simulator->simulateSeason($season, $rounds, $seasonIds, $teams, $baseSeed, $asOf);

            if ($season->format === SeasonFormat::SpringAutumn) {
                // 下部リーグが 3 チームしかないので、昇格は全チーム。順序だけ乱数で決める
                $promoted = array_slice(
                    (new SeededRandom($baseSeed, "promotion:{$season->name}"))->shuffle($lowerIds),
                    0,
                    DemoScenario::RELEGATION_SLOTS,
                );
                $result = new SeasonResult(
                    $result->definition, $result->teamIds, $result->rounds, $result->matches, $result->table,
                    $result->attempts, $result->penustaRank, $result->relegated, $promoted,
                );
                $topIds = array_values(array_diff($topIds, $result->relegated));
                array_push($topIds, ...$promoted);
                sort($topIds);
                $lowerIds = $result->relegated;
            }

            $results[] = $result;
        }

        return new DemoLeague($baseSeed, $results);
    }

    /**
     * ペヌスタ + 当時の J1 の 9 チーム。TeamSeeder の 9 チーム（id 2〜10）を優先し、足りなければ人気度の高い順
     *
     * @param  list<int>  $topIds
     * @param  array<int, DemoTeam>  $teams
     * @return list<int>
     */
    private function halfGroup(array $topIds, array $teams): array
    {
        $candidates = array_values(array_filter($topIds, fn (int $id) => $id !== DemoScenario::HOME_CLUB_ID));
        usort($candidates, fn (int $a, int $b) => [$a > 10, -$teams[$a]->popularity, $a] <=> [$b > 10, -$teams[$b]->popularity, $b]);
        $group = [DemoScenario::HOME_CLUB_ID, ...array_slice($candidates, 0, 9)];
        sort($group);

        return $group;
    }
}
