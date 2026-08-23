<?php

namespace Database\Seeders;

use App\Models\FootballMatch;
use App\Models\Season;
use App\Models\Stadium;
use App\Models\Team;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class MatchSeeder extends Seeder
{
    public function run(): void
    {
        $season = Season::create(['name' => '2026シーズン', 'year' => 2026]);
        $stadium = Stadium::firstOrFail();
        $homeTeam = Team::where('is_home_club', true)->firstOrFail();
        $awayTeams = Team::where('is_home_club', false)->orderBy('id')->get();

        // [date, kickoff hour, section number, opponent_popularity]
        $fixtures = [
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
        ];

        foreach ($fixtures as $i => [$date, $hour, $section, $popularity]) {
            // DBはUTC統一。JSTの開催時刻をUTCへ変換してから保存する
            $kickoff = Carbon::parse($date, 'Asia/Tokyo')->setTime($hour, 0)->utc();
            $awayTeam = $awayTeams[$i % $awayTeams->count()];

            FootballMatch::create([
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
                ],
            ]);
        }
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
