<?php

namespace Tests\Feature;

use App\Models\FootballMatch;
use App\Models\Season;
use App\Models\Stadium;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MatchIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_all_matches_sorted_by_kickoff_at(): void
    {
        $season = $this->createSeason();
        $homeTeam = $this->createTeam();
        $awayTeam = $this->createTeam();
        $stadium = $this->createStadium();

        // Create matches in reverse order to verify sorting
        $match2 = $this->createMatch(
            $season,
            $homeTeam,
            $awayTeam,
            $stadium,
            Carbon::parse('2026-03-08 10:00:00', 'UTC')
        );
        $match1 = $this->createMatch(
            $season,
            $homeTeam,
            $awayTeam,
            $stadium,
            Carbon::parse('2026-03-01 10:00:00', 'UTC')
        );

        $response = $this->get('/api/matches');

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertCount(2, $data);
        $this->assertLessThan($data[1]['kickoff_at'], $data[0]['kickoff_at']);
        $this->assertSame($match1->id, $data[0]['id']);
        $this->assertSame($match2->id, $data[1]['id']);
    }

    public function test_match_response_has_correct_structure(): void
    {
        $season = $this->createSeason();
        $homeTeam = $this->createTeam();
        $awayTeam = $this->createTeam();
        $stadium = $this->createStadium();

        $this->createMatch(
            $season,
            $homeTeam,
            $awayTeam,
            $stadium,
            Carbon::parse('2026-03-01 10:00:00', 'UTC')
        );

        $response = $this->get('/api/matches');

        $data = $response->json('data');
        $match = $data[0];

        $expectedKeys = ['id', 'kickoff_at', 'status', 'section_label', 'stadium', 'home_team', 'away_team'];
        $actualKeys = array_keys($match);
        sort($expectedKeys);
        sort($actualKeys);
        $this->assertEquals($expectedKeys, $actualKeys);

        $homeTeamKeys = ['id', 'name', 'short_name', 'color'];
        $actualHomeTeamKeys = array_keys($match['home_team']);
        sort($homeTeamKeys);
        sort($actualHomeTeamKeys);
        $this->assertEquals($homeTeamKeys, $actualHomeTeamKeys);

        $awayTeamKeys = ['id', 'name', 'short_name', 'color'];
        $actualAwayTeamKeys = array_keys($match['away_team']);
        sort($awayTeamKeys);
        sort($actualAwayTeamKeys);
        $this->assertEquals($awayTeamKeys, $actualAwayTeamKeys);

        $this->assertIsString($match['stadium']);
        $this->assertEquals($stadium->name, $match['stadium']);
        $this->assertSame($homeTeam->id, $match['home_team']['id']);
        $this->assertEquals($homeTeam->name, $match['home_team']['name']);
        $this->assertEquals($homeTeam->short_name, $match['home_team']['short_name']);
        $this->assertEquals($homeTeam->color, $match['home_team']['color']);
        $this->assertSame($awayTeam->id, $match['away_team']['id']);
        $this->assertEquals($awayTeam->name, $match['away_team']['name']);
        $this->assertEquals($awayTeam->short_name, $match['away_team']['short_name']);
        $this->assertEquals($awayTeam->color, $match['away_team']['color']);
    }

    public function test_kickoff_at_is_converted_to_jst(): void
    {
        $season = $this->createSeason();
        $homeTeam = $this->createTeam();
        $awayTeam = $this->createTeam();
        $stadium = $this->createStadium();

        $this->createMatch(
            $season,
            $homeTeam,
            $awayTeam,
            $stadium,
            Carbon::parse('2026-03-01 10:00:00', 'UTC')
        );

        $response = $this->get('/api/matches');

        $data = $response->json('data');
        $kickoffAt = $data[0]['kickoff_at'];

        // UTC 2026-03-01 10:00:00 -> JST 2026-03-01 19:00:00
        $this->assertEquals('2026-03-01T19:00:00+09:00', $kickoffAt);
    }

    public function test_returns_empty_data_when_no_matches(): void
    {
        $response = $this->get('/api/matches');

        $response->assertStatus(200);
        $response->assertJsonPath('data', []);
    }

    private function createSeason(): Season
    {
        return Season::create([
            'name' => 'Season 2026',
            'year' => 2026,
        ]);
    }

    private function createTeam(): Team
    {
        return Team::create([
            'name' => 'Test Team',
            'short_name' => 'TT',
            'color' => '#FF0000',
            'is_home_club' => false,
        ]);
    }

    private function createStadium(): Stadium
    {
        return Stadium::create([
            'name' => 'Test Stadium',
            'short_name' => 'TS',
        ]);
    }

    private function createMatch(
        Season $season,
        Team $homeTeam,
        Team $awayTeam,
        Stadium $stadium,
        Carbon $kickoffAt
    ): FootballMatch {
        return FootballMatch::create([
            'season_id' => $season->id,
            'home_team_id' => $homeTeam->id,
            'away_team_id' => $awayTeam->id,
            'stadium_id' => $stadium->id,
            'kickoff_at' => $kickoffAt,
            'status' => 'on_sale',
            'section_label' => 'Section A',
        ]);
    }
}
