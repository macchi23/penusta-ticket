<?php

namespace Database\Seeders;

use App\Models\AllocationTemplate;
use App\Models\FootballMatch;
use App\Models\Season;
use App\Models\Stadium;
use App\Models\Team;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * 既存の dev DB に未販売の試合（MatchSeeder::fixtures の unsold 付き）を後から足す。何度実行しても重複しない。
 * 実行: php artisan db:seed --class=AddUnsoldMatchesSeeder
 */
class AddUnsoldMatchesSeeder extends Seeder
{
    public function run(): void
    {
        $stadium = Stadium::firstOrFail();
        $season = Season::where('year', 2026)->firstOrFail();
        $homeTeam = Team::where('is_home_club', true)->firstOrFail();
        $awayTeams = Team::where('is_home_club', false)->orderBy('id')->get();
        $normalTemplate = AllocationTemplate::where('stadium_id', $stadium->id)->where('name', '通常配置')->firstOrFail();
        $bigAwayTemplate = AllocationTemplate::where('stadium_id', $stadium->id)->where('name', '大規模アウェー配置')->firstOrFail();

        $matchSeeder = new MatchSeeder;
        $allocationSeeder = new AllocationSeeder;
        $priceSeeder = new PriceSeeder;

        DB::transaction(function () use ($stadium, $season, $homeTeam, $awayTeams, $normalTemplate, $bigAwayTemplate, $matchSeeder, $allocationSeeder, $priceSeeder) {
            foreach (MatchSeeder::fixtures() as $i => $fixture) {
                if (! ($fixture[4] ?? false)) {
                    continue;
                }

                $exists = FootballMatch::where('stadium_id', $stadium->id)
                    ->where('kickoff_at', MatchSeeder::kickoffOf($fixture[0], $fixture[1]))
                    ->exists();
                if ($exists) {
                    continue;
                }

                $match = $matchSeeder->createMatch($season, $stadium, $homeTeam, $awayTeams, $i, $fixture);
                $allocationSeeder->allocateMatch($match, $normalTemplate, $bigAwayTemplate);
                $priceSeeder->priceMatch($match);
            }
        });
    }
}
