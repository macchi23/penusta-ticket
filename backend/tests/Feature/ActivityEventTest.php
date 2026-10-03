<?php

namespace Tests\Feature;

use App\Models\ActivityEvent;
use App\Models\Block;
use App\Models\FootballMatch;
use App\Models\Season;
use App\Models\SeatCategory;
use App\Models\Stadium;
use App\Models\Stand;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ActivityEventTest extends TestCase
{
    use RefreshDatabase;

    private const SESSION = '0b7a3c52-1d1e-4c2f-9a77-5f3e8d2b6a10';

    private FootballMatch $match;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ticketing.demo_auth_enabled' => true]);
        $this->travelTo(Carbon::parse('2026-06-01 00:00:00', 'UTC'));

        $stadium = Stadium::create(['name' => 'Test Stadium', 'short_name' => 'TS']);
        $stand = Stand::create(['stadium_id' => $stadium->id, 'name' => 'Main', 'code' => 'M']);
        $category = SeatCategory::create(['stadium_id' => $stadium->id, 'name' => 'S席', 'display_order' => 1]);
        $season = Season::create(['name' => 'Season 2026', 'year' => 2026]);
        $home = Team::create(['name' => 'H', 'short_name' => 'H', 'color' => '#000000', 'is_home_club' => true]);
        $away = Team::create(['name' => 'A', 'short_name' => 'A', 'color' => '#ffffff', 'is_home_club' => false]);
        $this->match = FootballMatch::create([
            'season_id' => $season->id, 'home_team_id' => $home->id, 'away_team_id' => $away->id,
            'stadium_id' => $stadium->id, 'kickoff_at' => Carbon::parse('2030-03-01 10:00:00', 'UTC'),
            'status' => 'on_sale', 'section_label' => 'A',
        ]);
        Block::create([
            'stand_id' => $stand->id, 'seat_category_id' => $category->id, 'code' => 'M1',
            'seating_type' => 'reserved', 'capacity' => 10, 'geometry' => ['type' => 'rect'],
        ]);
    }

    public function test_view_match_is_recorded_for_guest(): void
    {
        $this->postJson('/api/events', ['type' => 'view_match', 'match_id' => $this->match->id], [
            'X-Session-Id' => self::SESSION, 'X-Device' => 'mobile',
        ])->assertNoContent();

        $event = ActivityEvent::firstOrFail();
        $this->assertSame('view_match', $event->event_type);
        $this->assertNull($event->user_id);
        $this->assertSame(self::SESSION, $event->session_id);
        $this->assertSame('mobile', $event->device);
        $this->assertEquals(['match_id' => $this->match->id], $event->payload);
        $this->assertTrue($event->occurred_at->equalTo(Carbon::parse('2026-06-01 00:00:00', 'UTC')));
    }

    public function test_user_id_is_recorded_when_demo_user_present(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'a@example.com', 'password' => 'x']);

        $this->postJson('/api/events', ['type' => 'view_match', 'match_id' => $this->match->id], [
            'X-Demo-User-Id' => (string) $user->id,
        ])->assertNoContent();

        $this->assertSame($user->id, ActivityEvent::firstOrFail()->user_id);
        $this->assertNull(ActivityEvent::firstOrFail()->device);
    }

    public function test_view_seatmap_with_and_without_block_code(): void
    {
        $this->postJson('/api/events', ['type' => 'view_seatmap', 'match_id' => $this->match->id, 'block_code' => 'M1'])
            ->assertNoContent();
        $this->postJson('/api/events', ['type' => 'view_seatmap', 'match_id' => $this->match->id])
            ->assertNoContent();

        $events = ActivityEvent::orderBy('id')->get();
        $this->assertEquals(['match_id' => $this->match->id, 'block_code' => 'M1'], $events[0]->payload);
        $this->assertEquals(['match_id' => $this->match->id], $events[1]->payload);
    }

    public function test_block_code_is_ignored_for_view_match(): void
    {
        $this->postJson('/api/events', ['type' => 'view_match', 'match_id' => $this->match->id, 'block_code' => 'M1'])
            ->assertNoContent();

        $this->assertEquals(['match_id' => $this->match->id], ActivityEvent::firstOrFail()->payload);
    }

    public function test_invalid_requests_are_422_and_not_recorded(): void
    {
        $id = $this->match->id;
        $this->postJson('/api/events', ['type' => 'add_to_cart', 'match_id' => $id])->assertStatus(422);
        $this->postJson('/api/events', ['match_id' => $id])->assertStatus(422);
        $this->postJson('/api/events', ['type' => 'view_match', 'match_id' => $id + 999])->assertStatus(422);
        $this->postJson('/api/events', ['type' => 'view_seatmap', 'match_id' => $id, 'block_code' => 'NOPE'])->assertStatus(422);

        $this->assertSame(0, ActivityEvent::count());
    }

    public function test_invalid_session_id_becomes_unknown(): void
    {
        $this->postJson('/api/events', ['type' => 'view_match', 'match_id' => $this->match->id], [
            'X-Session-Id' => 'not-a-uuid',
        ])->assertNoContent();

        $this->assertSame('unknown', ActivityEvent::firstOrFail()->session_id);
    }

    public function test_rate_limit_is_per_session(): void
    {
        config(['ticketing.events_rate_per_minute' => 2]);
        $body = ['type' => 'view_match', 'match_id' => $this->match->id];
        $a = ['X-Session-Id' => self::SESSION];
        $b = ['X-Session-Id' => '1c8b4d63-2e2f-4d30-8b88-6a4f9e3c7b21'];

        $this->postJson('/api/events', $body, $a)->assertNoContent();
        $this->postJson('/api/events', $body, $a)->assertNoContent();
        $this->postJson('/api/events', $body, $a)->assertStatus(429);
        $this->postJson('/api/events', $body, $b)->assertNoContent();

        $this->assertSame(3, ActivityEvent::count());
    }

    public function test_rate_limit_per_ip_applies_across_sessions(): void
    {
        config(['ticketing.events_rate_per_minute_ip' => 2]);
        $body = ['type' => 'view_match', 'match_id' => $this->match->id];

        foreach (['1c8b4d63-2e2f-4d30-8b88-6a4f9e3c7b21', '2d9c5e74-3f3a-4e41-9c99-7b5a0f4d8c32'] as $id) {
            $this->postJson('/api/events', $body, ['X-Session-Id' => $id])->assertNoContent();
        }
        $this->postJson('/api/events', $body, ['X-Session-Id' => '3eadf685-4a4b-4f52-8daa-8c6b1a5e9d43'])
            ->assertStatus(429);

        $this->assertSame(2, ActivityEvent::count());
    }
}
