<?php

namespace App\Support\DemoData;

use DateTimeImmutable;
use RuntimeException;

/** 試合結果のシミュレーション、順位表、シナリオ条件を満たすまでの棄却サンプリング */
final class LeagueSimulator
{
    public const MAX_ATTEMPTS = 5000;

    private const FORM_LENGTH = 5;

    public function __construct(
        private readonly float $baseGoals = 1.2,
        private readonly float $homeAdvantage = 1.15,
    ) {}

    /**
     * 条件を満たすまで部分シード（attempt 0, 1, 2…）を進めてシミュレーションし直す
     *
     * @param  array<int, list<Fixture>>  $rounds
     * @param  list<int>  $teamIds
     * @param  array<int, DemoTeam>  $teams
     */
    public function simulateSeason(
        SeasonDefinition $season,
        array $rounds,
        array $teamIds,
        array $teams,
        int $baseSeed,
        DateTimeImmutable $asOf,
    ): SeasonResult {
        $strengths = [];
        foreach ($teamIds as $id) {
            $strengths[$id] = [$teams[$id]->attack, $teams[$id]->defense];
        }
        $strengths[DemoScenario::HOME_CLUB_ID] = [$season->penustaAttack, $season->penustaDefense];

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $rng = new SeededRandom($baseSeed, "season:{$season->name}:attempt:{$attempt}");
            $matches = $this->simulate($rounds, $strengths, $rng, $asOf);
            $table = $this->table($matches, $teamIds);

            if ($this->accepts($season, $matches, $table, $teamIds, $teams)) {
                $rank = $this->rankOf($table, DemoScenario::HOME_CLUB_ID);
                $relegated = [];
                if ($season->format === SeasonFormat::SpringAutumn) {
                    $relegated = array_map(
                        fn (StandingRow $row) => $row->teamId,
                        array_slice($table, -DemoScenario::RELEGATION_SLOTS),
                    );
                }

                return new SeasonResult($season, $teamIds, $rounds, $matches, $table, $attempt + 1, $rank, $relegated);
            }
        }

        throw new RuntimeException("シーズン {$season->name} が ".self::MAX_ATTEMPTS.' 回で条件を満たさなかった');
    }

    /**
     * @param  array<int, list<Fixture>>  $rounds
     * @param  array<int, array{0: float, 1: float}>  $strengths  チーム ID => [攻撃, 守備]
     * @return list<PlayedMatch>
     */
    public function simulate(array $rounds, array $strengths, SeededRandom $rng, DateTimeImmutable $asOf): array
    {
        $matches = [];
        foreach ($rounds as $fixtures) {
            foreach ($fixtures as $fixture) {
                [$homeAttack, $homeDefense] = $strengths[$fixture->homeId];
                [$awayAttack, $awayDefense] = $strengths[$fixture->awayId];
                // 結果を持たない試合でも乱数は消費する（同じ attempt なら AS_OF に関わらず得点は同じ。受理される attempt は AS_OF で変わりうる）
                $homeGoals = $rng->poisson($this->baseGoals * $homeAttack * $awayDefense * $this->homeAdvantage);
                $awayGoals = $rng->poisson($this->baseGoals * $awayAttack * $homeDefense);
                $played = $fixture->kickoffUtc < $asOf;
                $matches[] = new PlayedMatch($fixture, $played ? $homeGoals : null, $played ? $awayGoals : null);
            }
        }

        return $matches;
    }

    /**
     * 順位表。結果のある試合だけ数える。並びは 勝点 → 得失点差 → 総得点 → チーム ID
     *
     * @param  list<PlayedMatch>  $matches
     * @param  list<int>  $teamIds
     * @return list<StandingRow>
     */
    public function table(array $matches, array $teamIds): array
    {
        usort($matches, fn (PlayedMatch $a, PlayedMatch $b) => [$a->fixture->kickoffUtc, $a->fixture->round] <=> [$b->fixture->kickoffUtc, $b->fixture->round]);
        $stats = $this->emptyStats($teamIds);
        foreach ($matches as $match) {
            $this->apply($stats, $match);
        }

        return $this->rank($stats);
    }

    /**
     * 節ごとの順位表（その節までに結果のある試合）
     *
     * @param  list<PlayedMatch>  $matches
     * @param  list<int>  $teamIds
     * @return array<int, list<StandingRow>> 節番号 => 順位表
     */
    public function roundTables(array $matches, array $teamIds): array
    {
        $byRound = [];
        foreach ($matches as $match) {
            $byRound[$match->fixture->round][] = $match;
        }
        ksort($byRound);

        $stats = $this->emptyStats($teamIds);
        $tables = [];
        foreach ($byRound as $round => $roundMatches) {
            usort($roundMatches, fn (PlayedMatch $a, PlayedMatch $b) => $a->fixture->kickoffUtc <=> $b->fixture->kickoffUtc);
            foreach ($roundMatches as $match) {
                $this->apply($stats, $match);
            }
            $tables[$round] = $this->rank($stats);
        }

        return $tables;
    }

    /**
     * @param  list<PlayedMatch>  $matches
     * @param  list<StandingRow>  $table
     * @param  list<int>  $teamIds
     * @param  array<int, DemoTeam>  $teams
     */
    private function accepts(SeasonDefinition $season, array $matches, array $table, array $teamIds, array $teams): bool
    {
        $rank = $this->rankOf($table, DemoScenario::HOME_CLUB_ID);
        if ($rank < $season->minRank || $rank > $season->maxRank) {
            return false;
        }

        if ($season->maxGapToChampion !== null) {
            $penusta = $table[$rank - 1]->points;
            if ($table[0]->points - $penusta > $season->maxGapToChampion) {
                return false;
            }
        }

        if ($season->format === SeasonFormat::SpringAutumn) {
            foreach (array_slice($table, -DemoScenario::RELEGATION_SLOTS) as $row) {
                if ($teams[$row->teamId]->popularity >= DemoScenario::NO_RELEGATION_POPULARITY) {
                    return false;
                }
            }
        }

        if ($season->lowRankFrom === null && $season->leaderFromRound === null) {
            return true;
        }

        $lowSeen = $season->lowRankFrom === null;
        $leaderSeen = $season->leaderFromRound === null;
        foreach ($this->roundTables($matches, $teamIds) as $round => $roundTable) {
            $penustaRank = $this->rankOf($roundTable, DemoScenario::HOME_CLUB_ID);
            if ($season->lowRankFrom !== null && $round >= $season->lowRankFrom['round'] && $penustaRank >= $season->lowRankFrom['rank']) {
                $lowSeen = true;
            }
            if ($season->leaderFromRound !== null && $round >= $season->leaderFromRound && $penustaRank === 1) {
                $leaderSeen = true;
            }
        }

        return $lowSeen && $leaderSeen;
    }

    /** @param  list<StandingRow>  $table */
    private function rankOf(array $table, int $teamId): int
    {
        foreach ($table as $row) {
            if ($row->teamId === $teamId) {
                return $row->rank;
            }
        }

        throw new RuntimeException("team {$teamId} not in table");
    }

    /**
     * @param  list<int>  $teamIds
     * @return array<int, array{played: int, won: int, drawn: int, lost: int, gf: int, ga: int, points: int, form: list<string>}>
     */
    private function emptyStats(array $teamIds): array
    {
        $stats = [];
        foreach ($teamIds as $id) {
            $stats[$id] = ['played' => 0, 'won' => 0, 'drawn' => 0, 'lost' => 0, 'gf' => 0, 'ga' => 0, 'points' => 0, 'form' => []];
        }

        return $stats;
    }

    private function apply(array &$stats, PlayedMatch $match): void
    {
        if (! $match->isPlayed()) {
            return;
        }
        $sides = [
            [$match->fixture->homeId, $match->homeGoals, $match->awayGoals],
            [$match->fixture->awayId, $match->awayGoals, $match->homeGoals],
        ];
        foreach ($sides as [$id, $for, $against]) {
            $s = &$stats[$id];
            $s['played']++;
            $s['gf'] += $for;
            $s['ga'] += $against;
            if ($for > $against) {
                $s['won']++;
                $s['points'] += 3;
                $result = 'W';
            } elseif ($for === $against) {
                $s['drawn']++;
                $s['points']++;
                $result = 'D';
            } else {
                $s['lost']++;
                $result = 'L';
            }
            $s['form'][] = $result;
            if (count($s['form']) > self::FORM_LENGTH) {
                array_shift($s['form']);
            }
            unset($s);
        }
    }

    /** @return list<StandingRow> */
    private function rank(array $stats): array
    {
        $ids = array_keys($stats);
        usort($ids, fn (int $a, int $b) => [$stats[$b]['points'], $stats[$b]['gf'] - $stats[$b]['ga'], $stats[$b]['gf'], $a]
            <=> [$stats[$a]['points'], $stats[$a]['gf'] - $stats[$a]['ga'], $stats[$a]['gf'], $b]);

        $rows = [];
        foreach ($ids as $i => $id) {
            $s = $stats[$id];
            $rows[] = new StandingRow($id, $i + 1, $s['played'], $s['won'], $s['drawn'], $s['lost'], $s['gf'], $s['ga'], $s['points'], implode('', $s['form']));
        }

        return $rows;
    }
}
