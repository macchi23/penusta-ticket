<?php

namespace Database\Seeders;

use App\Models\FootballMatch;
use App\Models\Season;
use App\Models\Stadium;
use App\Models\Team;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

class MatchSeeder extends Seeder
{
    public function run(): void
    {
        $season = Season::create(['name' => '2026シーズン', 'year' => 2026]);
        $stadium = Stadium::firstOrFail();
        $homeTeam = Team::where('is_home_club', true)->firstOrFail();
        $awayTeams = Team::where('is_home_club', false)->orderBy('id')->get();

        foreach (self::fixtures() as $i => $fixture) {
            $this->createMatch($season, $stadium, $homeTeam, $awayTeams, $i, $fixture);
        }
    }

    /**
     * 未販売（tickets を作らない）の印は attributes.unsold。GenerateDemoData がこれを見てスキップする。
     */
    public const UNSOLD_KEY = 'unsold';

    /**
     * @return array<int, array{0:string, 1:int, 2:int, 3:int, 4?:bool}> [date, kickoff hour, section number, opponent_popularity, unsold]
     */
    public static function fixtures(): array
    {
        return [
            ['2026-03-07', 19, 2, 3],
            ['2026-03-21', 14, 4, 2],
            ['2026-04-11', 19, 6, 4],
            ['2026-04-29', 14, 9, 3],
            ['2026-05-09', 19, 11, 2],
            ['2026-06-20', 19, 14, 4],
            ['2026-07-18', 14, 18, 3],
            ['2026-08-30', 14, 24, 5],
            ['2026-09-26', 19, 27, 3],
            ['2026-11-21', 14, 33, 5],
            ['2026-11-28', 14, 37, 3, true],
            ['2026-12-05', 14, 38, 4, true],
        ];
    }

    public static function kickoffOf(string $date, int $hour): Carbon
    {
        // DBはUTC統一。JSTの開催時刻をUTCへ変換してから保存する
        return Carbon::parse($date, 'Asia/Tokyo')->setTime($hour, 0)->utc();
    }

    /**
     * @param  Collection<int, Team>  $awayTeams
     * @param  array{0:string, 1:int, 2:int, 3:int, 4?:bool}  $fixture
     */
    public function createMatch(Season $season, Stadium $stadium, Team $homeTeam, $awayTeams, int $index, array $fixture): FootballMatch
    {
        [$date, $hour, $section, $popularity] = $fixture;
        $kickoff = self::kickoffOf($date, $hour);
        $awayTeam = $awayTeams[$index % $awayTeams->count()];

        return FootballMatch::create([
            'season_id' => $season->id,
            'home_team_id' => $homeTeam->id,
            'away_team_id' => $awayTeam->id,
            'stadium_id' => $stadium->id,
            'kickoff_at' => $kickoff,
            'status' => 'on_sale',
            'section_label' => '第'.$section.'節',
            'attributes' => [
                'weather' => $this->weatherFor($kickoff),
                'temperature' => $this->temperatureFor($kickoff),
                'day_type' => in_array($kickoff->dayOfWeek, [0, 6], true) ? 'weekend' : 'weekday',
                'opponent_popularity' => $popularity,
            ] + (($fixture[4] ?? false) ? [self::UNSOLD_KEY => true] : []),
        ]);
    }

    private function weatherFor(Carbon $kickoff): string
    {
        $month = $kickoff->month;

        return match (true) {
            in_array($month, [6, 7], true) => ['曇', '雨', '曇', '晴'][($kickoff->day) % 4],
            in_array($month, [3, 11], true) => ['晴', '曇', '晴'][($kickoff->day) % 3],
            default => ['晴', '晴', '曇'][($kickoff->day) % 3],
        };
    }

    private function temperatureFor(Carbon $kickoff): int
    {
        return match ($kickoff->month) {
            3 => 13,
            4 => 18,
            5 => 22,
            6 => 25,
            7 => 29,
            8 => 32,
            9 => 28,
            10 => 21,
            11 => 15,
            default => 20,
        } + (($kickoff->day) % 5 - 2);
    }
}
