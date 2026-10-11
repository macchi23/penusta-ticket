<?php

namespace Database\Seeders;

use App\Models\FootballMatch;
use App\Models\LeagueMatch;
use App\Models\LeagueStanding;
use App\Models\Season;
use App\Models\Stadium;
use App\Services\SeasonPass\SalePhaseProvisioner;
use App\Support\DemoData\DemoLeague;
use App\Support\DemoData\DemoLeagueBuilder;
use App\Support\DemoData\DemoScenario;
use App\Support\DemoData\Fixture;
use App\Support\DemoData\JapaneseHolidays;
use App\Support\DemoData\LeagueSimulator;
use App\Support\DemoData\PlayedMatch;
use App\Support\DemoData\SeasonFormat;
use App\Support\DemoData\SeasonResult;
use App\Support\DemoData\Weather;
use Carbon\Carbon;
use DateTimeImmutable;
use Illuminate\Database\Seeder;

class MatchSeeder extends Seeder
{
    /**
     * 未販売（tickets を作らない）の印は attributes.unsold。GenerateDemoData がこれを見てスキップする。
     */
    public const UNSOLD_KEY = 'unsold';

    /** AS_OF より後のホーム戦のうち、キックオフの早い順にこの数だけは販売途中（unsold の印を付けない） */
    public const SELLING_MATCH_COUNT = 3;

    private const CHUNK = 500;

    private static ?DemoLeague $league = null;

    /** 7 シーズン分のリーグ。build は重いのでプロセス内で使い回す（DatabaseSeeder をテストで何度も呼ぶため） */
    public static function league(): DemoLeague
    {
        return self::$league ??= (new DemoLeagueBuilder)->build();
    }

    public function run(): void
    {
        $league = self::league();
        $asOf = DemoScenario::asOf()->setTimezone(new \DateTimeZone('UTC'));
        $stadium = Stadium::firstOrFail();
        $weather = new Weather($league->baseSeed);
        $holidays = new JapaneseHolidays;
        $teams = DemoScenario::teams();
        $provisioner = app(SalePhaseProvisioner::class);

        // AS_OF より後のホーム戦をキックオフ順に並べ、先頭の数試合だけ販売途中にする
        $future = [];
        foreach ($league->seasons as $result) {
            foreach ($this->homeFixtures($result) as $fixture) {
                if ($fixture->kickoffUtc >= $asOf) {
                    $future[] = $fixture->kickoffUtc;
                }
            }
        }
        sort($future);
        $sellingUntil = $future[self::SELLING_MATCH_COUNT - 1] ?? null;

        foreach ($league->seasons as $result) {
            $definition = $result->definition;
            $allFixtures = array_merge(...array_values($result->rounds));
            $season = Season::create([
                'name' => $definition->format === SeasonFormat::Half ? $definition->name.' ハーフ' : $definition->name,
                'year' => $definition->startYear,
                'format' => $definition->format->value,
                'starts_on' => $this->jstDate(min(array_map(fn (Fixture $f) => $f->kickoffUtc, $allFixtures))),
                'ends_on' => $this->jstDate(max(array_map(fn (Fixture $f) => $f->kickoffUtc, $allFixtures))),
            ]);

            $matchIds = [];
            foreach ($this->homeFixtures($result) as $fixture) {
                $kickoff = Carbon::instance($fixture->kickoffUtc)->utc();
                $jst = $kickoff->copy()->setTimezone(DemoScenario::TIMEZONE);
                $report = $weather->forKickoff($fixture->kickoffUtc);
                $isFuture = $fixture->kickoffUtc >= $asOf;

                $match = FootballMatch::create([
                    'season_id' => $season->id,
                    'home_team_id' => $fixture->homeId,
                    'away_team_id' => $fixture->awayId,
                    'stadium_id' => $stadium->id,
                    'kickoff_at' => $kickoff,
                    'status' => $isFuture ? 'on_sale' : 'finished',
                    'section_label' => '第'.$fixture->round.'節',
                    'attributes' => [
                        'weather' => $report->condition->value,
                        'temperature' => $report->temperature,
                        'day_type' => $jst->isWeekend() ? 'weekend' : 'weekday',
                        'is_holiday' => $holidays->isHoliday($jst),
                        'opponent_popularity' => $teams[$fixture->awayId]->popularity,
                        'promo' => false,
                        'round' => $fixture->round,
                        'round_ratio' => round($fixture->round / $definition->roundCount(), 4),
                    ] + ($isFuture && $fixture->kickoffUtc > $sellingUntil ? [self::UNSOLD_KEY => true] : []),
                ]);
                $provisioner->provision($match);
                $matchIds[$fixture->round] = $match->id;
            }

            $this->saveLeagueMatches($season, $result, $matchIds);
            $this->saveStandings($season, $result);
        }
    }

    /** @return list<Fixture> */
    private function homeFixtures(SeasonResult $result): array
    {
        $fixtures = array_filter(
            array_merge(...array_values($result->rounds)),
            fn (Fixture $f) => $f->homeId === DemoScenario::HOME_CLUB_ID,
        );
        usort($fixtures, fn (Fixture $a, Fixture $b) => $a->kickoffUtc <=> $b->kickoffUtc);

        return $fixtures;
    }

    /** @param  array<int, int>  $matchIds  節 => matches.id（ペヌスタのホーム戦） */
    private function saveLeagueMatches(Season $season, SeasonResult $result, array $matchIds): void
    {
        $now = now();
        $rows = array_map(fn (PlayedMatch $m) => [
            'season_id' => $season->id,
            'round' => $m->fixture->round,
            'kickoff_at' => Carbon::instance($m->fixture->kickoffUtc)->utc(),
            'home_team_id' => $m->fixture->homeId,
            'away_team_id' => $m->fixture->awayId,
            'home_score' => $m->homeGoals,
            'away_score' => $m->awayGoals,
            'match_id' => $m->fixture->homeId === DemoScenario::HOME_CLUB_ID ? $matchIds[$m->fixture->round] : null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $result->matches);

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            LeagueMatch::insert($chunk);
        }
    }

    /** 結果のある節だけ保存する（AS_OF より後の節は順位表が存在しない） */
    private function saveStandings(Season $season, SeasonResult $result): void
    {
        $now = now();
        $playedRounds = [];
        foreach ($result->matches as $m) {
            if ($m->isPlayed()) {
                $playedRounds[$m->fixture->round] = true;
            }
        }

        $rows = [];
        foreach ((new LeagueSimulator)->roundTables($result->matches, $result->teamIds) as $round => $table) {
            if (! isset($playedRounds[$round])) {
                continue;
            }
            foreach ($table as $row) {
                $rows[] = [
                    'season_id' => $season->id,
                    'round' => $round,
                    'team_id' => $row->teamId,
                    'position' => $row->rank,
                    'played' => $row->played,
                    'won' => $row->won,
                    'drawn' => $row->drawn,
                    'lost' => $row->lost,
                    'goals_for' => $row->goalsFor,
                    'goals_against' => $row->goalsAgainst,
                    'points' => $row->points,
                    'form' => $row->form,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            LeagueStanding::insert($chunk);
        }
    }

    private function jstDate(DateTimeImmutable $utc): string
    {
        return $utc->setTimezone(new \DateTimeZone(DemoScenario::TIMEZONE))->format('Y-m-d');
    }
}
