<?php

namespace Tests\Feature;

use App\Models\FootballMatch;
use App\Models\LeagueMatch;
use App\Models\LeagueStanding;
use App\Models\MatchPrice;
use App\Models\Season;
use App\Models\Team;
use App\Support\DemoData\DemoScenario;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\MatchSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeagueSeedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_teams_match_scenario_ids(): void
    {
        $this->assertSame(23, Team::count());
        foreach (DemoScenario::teams() as $id => $team) {
            $this->assertSame($team->name, Team::findOrFail($id)->name);
        }
        $this->assertTrue(Team::findOrFail(DemoScenario::HOME_CLUB_ID)->is_home_club);
    }

    public function test_seasons_have_format_and_period(): void
    {
        $seasons = Season::orderBy('id')->get();
        $this->assertSame(['2021', '2022', '2023', '2024', '2025', '2026 ハーフ', '2026-27'], $seasons->pluck('name')->all());
        $this->assertSame(
            ['spring_autumn', 'spring_autumn', 'spring_autumn', 'spring_autumn', 'spring_autumn', 'half', 'autumn_spring'],
            $seasons->pluck('format')->all(),
        );
        foreach ($seasons as $season) {
            $this->assertNotNull($season->starts_on);
            $this->assertTrue($season->ends_on->greaterThan($season->starts_on));
        }
        $this->assertSame(2026, $seasons->last()->year);
        $this->assertSame(2021, $seasons->first()->year);
    }

    public function test_home_matches_per_season(): void
    {
        $counts = Season::orderBy('id')->get()->map(fn (Season $s) => $s->matches()->count())->all();
        $this->assertSame([19, 19, 19, 19, 19, 9, 19], $counts);
        $this->assertSame(123, FootballMatch::count());
        $this->assertSame(0, FootballMatch::where('home_team_id', '!=', DemoScenario::HOME_CLUB_ID)->count());
    }

    public function test_league_matches_link_one_to_one_with_matches(): void
    {
        $this->assertSame(380 * 5 + 90 + 380, LeagueMatch::count());

        $linked = LeagueMatch::whereNotNull('match_id')->get();
        $this->assertSame(123, $linked->count());
        $this->assertSame(123, $linked->pluck('match_id')->unique()->count());
        foreach ($linked as $lm) {
            $match = FootballMatch::findOrFail($lm->match_id);
            $this->assertSame($lm->season_id, $match->season_id);
            $this->assertSame($lm->home_team_id, $match->home_team_id);
            $this->assertSame($lm->away_team_id, $match->away_team_id);
            $this->assertTrue($lm->kickoff_at->equalTo($match->kickoff_at));
            $this->assertSame($lm->round, $match->attributes['round']);
        }
        // ペヌスタのホーム戦以外は matches に紐づかない
        $this->assertSame(0, LeagueMatch::whereNull('match_id')->where('home_team_id', DemoScenario::HOME_CLUB_ID)->count());
    }

    public function test_final_standings_match_recomputed_from_scores(): void
    {
        foreach (Season::whereIn('name', ['2021', '2026 ハーフ'])->get() as $season) {
            $lastRound = LeagueStanding::where('season_id', $season->id)->max('round');
            $this->assertSame($season->name === '2021' ? 38 : 18, $lastRound);

            $expected = [];
            foreach (LeagueMatch::where('season_id', $season->id)->whereNotNull('home_score')->get() as $m) {
                foreach ([[$m->home_team_id, $m->home_score, $m->away_score], [$m->away_team_id, $m->away_score, $m->home_score]] as [$team, $for, $against]) {
                    $e = &$expected[$team];
                    $e ??= ['played' => 0, 'won' => 0, 'drawn' => 0, 'lost' => 0, 'gf' => 0, 'ga' => 0, 'points' => 0];
                    $e['played']++;
                    $e['gf'] += $for;
                    $e['ga'] += $against;
                    $e[$for > $against ? 'won' : ($for === $against ? 'drawn' : 'lost')]++;
                    $e['points'] += $for > $against ? 3 : ($for === $against ? 1 : 0);
                    unset($e);
                }
            }

            $rows = LeagueStanding::where('season_id', $season->id)->where('round', $lastRound)->get();
            $this->assertCount(count($expected), $rows);
            foreach ($rows as $row) {
                $e = $expected[$row->team_id];
                $this->assertSame(
                    [$e['played'], $e['won'], $e['drawn'], $e['lost'], $e['gf'], $e['ga'], $e['points']],
                    [$row->played, $row->won, $row->drawn, $row->lost, $row->goals_for, $row->goals_against, $row->points],
                );
            }
            $this->assertSame(range(1, count($expected)), $rows->pluck('position')->sort()->values()->all());
        }
    }

    public function test_current_season_boundary_is_as_of(): void
    {
        $asOf = DemoScenario::asOf();
        $season = Season::where('name', '2026-27')->firstOrFail();
        $matches = $season->matches()->orderBy('kickoff_at')->get();

        foreach ($matches as $match) {
            $this->assertSame($match->kickoff_at < $asOf ? 'finished' : 'on_sale', $match->status);
        }
        $this->assertGreaterThan(0, $matches->where('status', 'finished')->count());

        $onSale = $matches->where('status', 'on_sale')->values();
        foreach ($onSale as $i => $match) {
            $this->assertSame($i >= MatchSeeder::SELLING_MATCH_COUNT, $match->attributes[MatchSeeder::UNSOLD_KEY] ?? false);
        }
        $this->assertGreaterThan(MatchSeeder::SELLING_MATCH_COUNT, $onSale->count());

        // 結果は AS_OF より前の試合だけ
        foreach (LeagueMatch::where('season_id', $season->id)->get() as $lm) {
            $this->assertSame($lm->kickoff_at < $asOf, $lm->home_score !== null);
        }
    }

    public function test_match_attributes_hold_only_pre_match_conditions(): void
    {
        $keys = ['weather', 'temperature', 'day_type', 'is_holiday', 'opponent_popularity', 'promo', 'round', 'round_ratio'];
        foreach (FootballMatch::all() as $match) {
            $attributes = $match->attributes;
            unset($attributes[MatchSeeder::UNSOLD_KEY]);
            $this->assertEqualsCanonicalizing($keys, array_keys($attributes));
            $this->assertContains($attributes['day_type'], ['weekday', 'weekend']);
            $this->assertGreaterThan(0, $attributes['round_ratio']);
            $this->assertLessThanOrEqual(1, $attributes['round_ratio']);
        }
    }

    public function test_every_match_has_twelve_prices_and_promo_is_cheaper(): void
    {
        foreach (Season::all() as $season) {
            $matches = $season->matches;
            $promo = $matches->filter(fn ($m) => $m->attributes['promo'] === true);
            $this->assertCount($season->format === 'half' ? 1 : 2, $promo, $season->name);

            foreach ($matches as $match) {
                $this->assertSame(12, MatchPrice::where('match_id', $match->id)->count());
            }

            $adult = fn (FootballMatch $m) => MatchPrice::where('match_id', $m->id)
                ->whereHas('seatCategory', fn ($q) => $q->where('name', 'カテゴリー1'))
                ->whereHas('ticketType', fn ($q) => $q->where('code', 'adult'))
                ->value('price');
            $regular = $matches->first(fn ($m) => ! $m->attributes['promo'] && $m->attributes['opponent_popularity'] < 5);
            foreach ($promo as $match) {
                $this->assertLessThan($adult($regular), $adult($match), $season->name);
            }
        }
    }

    public function test_season_price_revisions(): void
    {
        $expected = ['2021' => 5700, '2022' => 5700, '2023' => 5800, '2024' => 5900, '2025' => 6000, '2026 ハーフ' => 6200, '2026-27' => 6300];
        foreach ($expected as $name => $price) {
            $match = Season::where('name', $name)->firstOrFail()->matches()
                ->get()->first(fn ($m) => ! $m->attributes['promo'] && $m->attributes['opponent_popularity'] < 5);
            $row = MatchPrice::where('match_id', $match->id)
                ->whereHas('seatCategory', fn ($q) => $q->where('name', 'カテゴリー1'))
                ->whereHas('ticketType', fn ($q) => $q->where('code', 'adult'))
                ->firstOrFail();
            $this->assertSame($price, $row->price, $name);
        }
        $this->assertSame(0, MatchPrice::whereHas('ticketType', fn ($q) => $q->where('code', 'season_pass'))->where('price', '!=', 0)->count());
    }

    public function test_youth_prices_scale_proportionally_and_round_to_hundred(): void
    {
        // Test 2021 and 2022 for cat1 and cat2
        $testCases = [
            ['season' => '2021', 'cat' => 'カテゴリー1', 'baseAdult' => 6000, 'baseYouth' => 3000, 'seasonAdj' => -300],
            ['season' => '2021', 'cat' => 'カテゴリー2', 'baseAdult' => 5000, 'baseYouth' => 2500, 'seasonAdj' => -300],
            ['season' => '2022', 'cat' => 'カテゴリー1', 'baseAdult' => 6000, 'baseYouth' => 3000, 'seasonAdj' => -300],
            ['season' => '2022', 'cat' => 'カテゴリー2', 'baseAdult' => 5000, 'baseYouth' => 2500, 'seasonAdj' => -300],
        ];

        foreach ($testCases as $tc) {
            $match = Season::where('name', $tc['season'])->firstOrFail()->matches()
                ->get()->first(fn ($m) => ! $m->attributes['promo'] && $m->attributes['opponent_popularity'] < 5);

            $adultPrice = MatchPrice::where('match_id', $match->id)
                ->whereHas('seatCategory', fn ($q) => $q->where('name', $tc['cat']))
                ->whereHas('ticketType', fn ($q) => $q->where('code', 'adult'))
                ->value('price');

            $youthPrice = MatchPrice::where('match_id', $match->id)
                ->whereHas('seatCategory', fn ($q) => $q->where('name', $tc['cat']))
                ->whereHas('ticketType', fn ($q) => $q->where('code', 'youth'))
                ->value('price');

            $expectedAdult = $tc['baseAdult'] + $tc['seasonAdj'];
            $ratio = $expectedAdult / $tc['baseAdult'];
            $expectedYouth = (int) (round($tc['baseYouth'] * $ratio / 100) * 100);

            $this->assertSame($expectedAdult, $adultPrice, "{$tc['season']} {$tc['cat']} adult");
            $this->assertSame($expectedYouth, $youthPrice, "{$tc['season']} {$tc['cat']} youth");
            $this->assertSame($expectedYouth % 100, 0, "{$tc['season']} {$tc['cat']} youth is 100-yen unit");
        }
    }

    public function test_big_match_surcharge_applies_to_adult_and_youth_not_season_pass(): void
    {
        // Find a big match
        $bigMatch = FootballMatch::where('season_id', Season::where('name', '2025')->firstOrFail()->id)
            ->get()
            ->first(fn ($m) => ! $m->attributes['promo'] && ($m->attributes['opponent_popularity'] ?? 1) >= 5);

        $this->assertNotNull($bigMatch, 'No big match found in 2025');

        // Find a normal match to compare
        $normalMatch = Season::where('name', '2025')->firstOrFail()->matches()
            ->get()->first(fn ($m) => ! $m->attributes['promo'] && ($m->attributes['opponent_popularity'] ?? 1) < 5);

        $this->assertNotNull($normalMatch);

        $categories = ['カテゴリー1', 'カテゴリー2', 'ゴール裏'];
        foreach ($categories as $cat) {
            $adultBig = MatchPrice::where('match_id', $bigMatch->id)
                ->whereHas('seatCategory', fn ($q) => $q->where('name', $cat))
                ->whereHas('ticketType', fn ($q) => $q->where('code', 'adult'))
                ->value('price');

            $adultNormal = MatchPrice::where('match_id', $normalMatch->id)
                ->whereHas('seatCategory', fn ($q) => $q->where('name', $cat))
                ->whereHas('ticketType', fn ($q) => $q->where('code', 'adult'))
                ->value('price');

            $this->assertSame(500, $adultBig - $adultNormal, "{$cat} adult");

            // Youth should also have +500
            $youthBig = MatchPrice::where('match_id', $bigMatch->id)
                ->whereHas('seatCategory', fn ($q) => $q->where('name', $cat))
                ->whereHas('ticketType', fn ($q) => $q->where('code', 'youth'))
                ->value('price');

            $youthNormal = MatchPrice::where('match_id', $normalMatch->id)
                ->whereHas('seatCategory', fn ($q) => $q->where('name', $cat))
                ->whereHas('ticketType', fn ($q) => $q->where('code', 'youth'))
                ->value('price');

            $this->assertSame(500, $youthBig - $youthNormal, "{$cat} youth");

            // Season pass should not have surcharge
            $spBig = MatchPrice::where('match_id', $bigMatch->id)
                ->whereHas('seatCategory', fn ($q) => $q->where('name', $cat))
                ->whereHas('ticketType', fn ($q) => $q->where('code', 'season_pass'))
                ->value('price');

            $spNormal = MatchPrice::where('match_id', $normalMatch->id)
                ->whereHas('seatCategory', fn ($q) => $q->where('name', $cat))
                ->whereHas('ticketType', fn ($q) => $q->where('code', 'season_pass'))
                ->value('price');

            $this->assertSame($spBig, $spNormal, "{$cat} season_pass");
        }
    }

    public function test_promo_adult_discount_and_youth_unchanged(): void
    {
        // Get a promo match
        $promoMatch = FootballMatch::all()
            ->first(fn ($m) => ($m->attributes['promo'] ?? false) === true);

        $this->assertNotNull($promoMatch);

        // Get a similar non-promo match (same season, no big match, no promo)
        $nonPromoMatch = Season::where('id', $promoMatch->season_id)->firstOrFail()->matches()
            ->get()->first(fn ($m) => ! $m->attributes['promo']
                && ($m->attributes['opponent_popularity'] ?? 1) < 5
                && $m->id !== $promoMatch->id);

        $this->assertNotNull($nonPromoMatch);

        $categories = ['カテゴリー1', 'カテゴリー2', 'カテゴリー3', 'ゴール裏'];
        foreach ($categories as $cat) {
            $adultPromo = MatchPrice::where('match_id', $promoMatch->id)
                ->whereHas('seatCategory', fn ($q) => $q->where('name', $cat))
                ->whereHas('ticketType', fn ($q) => $q->where('code', 'adult'))
                ->value('price');

            $adultNonPromo = MatchPrice::where('match_id', $nonPromoMatch->id)
                ->whereHas('seatCategory', fn ($q) => $q->where('name', $cat))
                ->whereHas('ticketType', fn ($q) => $q->where('code', 'adult'))
                ->value('price');

            // Promo adult should be 85% of non-promo, rounded to 100 yen
            $expected = (int) (round($adultNonPromo * 0.85 / 100) * 100);
            $this->assertSame($expected, $adultPromo, "{$cat} adult promo");

            // Youth price should remain same
            $youthPromo = MatchPrice::where('match_id', $promoMatch->id)
                ->whereHas('seatCategory', fn ($q) => $q->where('name', $cat))
                ->whereHas('ticketType', fn ($q) => $q->where('code', 'youth'))
                ->value('price');

            $youthNonPromo = MatchPrice::where('match_id', $nonPromoMatch->id)
                ->whereHas('seatCategory', fn ($q) => $q->where('name', $cat))
                ->whereHas('ticketType', fn ($q) => $q->where('code', 'youth'))
                ->value('price');

            $this->assertSame($youthNonPromo, $youthPromo, "{$cat} youth promo");

            // Season pass price should remain same
            $spPromo = MatchPrice::where('match_id', $promoMatch->id)
                ->whereHas('seatCategory', fn ($q) => $q->where('name', $cat))
                ->whereHas('ticketType', fn ($q) => $q->where('code', 'season_pass'))
                ->value('price');

            $spNonPromo = MatchPrice::where('match_id', $nonPromoMatch->id)
                ->whereHas('seatCategory', fn ($q) => $q->where('name', $cat))
                ->whereHas('ticketType', fn ($q) => $q->where('code', 'season_pass'))
                ->value('price');

            $this->assertSame($spNonPromo, $spPromo, "{$cat} season_pass promo");
        }
    }

    public function test_promo_match_is_not_big_match(): void
    {
        foreach (FootballMatch::all() as $match) {
            $isPromo = ($match->attributes['promo'] ?? false) === true;
            $isBigMatch = ($match->attributes['opponent_popularity'] ?? 1) >= 5;

            if ($isPromo) {
                $this->assertFalse($isBigMatch, "Match {$match->id} is promo but also big match");
            }
        }
    }
}
