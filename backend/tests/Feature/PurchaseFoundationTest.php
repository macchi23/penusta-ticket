<?php

namespace Tests\Feature;

use App\Models\Block;
use App\Models\FootballMatch;
use App\Models\MatchBlockAllocation;
use App\Models\MatchPrice;
use App\Models\Membership;
use App\Models\Season;
use App\Models\Seat;
use App\Models\SeatCategory;
use App\Models\Stadium;
use App\Models\Stand;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class PurchaseFoundationTest extends TestCase
{
    use RefreshDatabase;

    private FootballMatch $match;

    private Block $block;

    /** @var array<int, Seat> */
    private array $seats = [];

    protected function setUp(): void
    {
        parent::setUp();

        $stadium = Stadium::create(['name' => 'Test Stadium', 'short_name' => 'TS']);
        $stand = Stand::create(['stadium_id' => $stadium->id, 'name' => 'Main', 'code' => 'M']);
        $category = SeatCategory::create(['stadium_id' => $stadium->id, 'name' => 'S席', 'display_order' => 1]);
        $season = Season::create(['name' => 'Season 2026', 'year' => 2026]);
        $home = Team::create(['name' => 'H', 'short_name' => 'H', 'color' => '#000000', 'is_home_club' => true]);
        $away = Team::create(['name' => 'A', 'short_name' => 'A', 'color' => '#ffffff', 'is_home_club' => false]);

        $this->match = FootballMatch::create([
            'season_id' => $season->id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'stadium_id' => $stadium->id,
            'kickoff_at' => Carbon::parse('2030-03-01 10:00:00', 'UTC'),
            'status' => 'on_sale',
            'section_label' => 'A',
        ]);

        $this->block = Block::create([
            'stand_id' => $stand->id,
            'seat_category_id' => $category->id,
            'code' => 'M1',
            'seating_type' => 'reserved',
            'capacity' => 5,
            'geometry' => ['type' => 'rect'],
        ]);

        MatchBlockAllocation::create([
            'match_id' => $this->match->id,
            'block_id' => $this->block->id,
            'allocation' => 'home',
        ]);

        foreach (range(1, 5) as $n) {
            $this->seats[$n] = Seat::create(['block_id' => $this->block->id, 'row_label' => 'A', 'number' => $n]);
        }
    }

    public function test_inventory_counts_only_paid_and_unexpired_held(): void
    {
        $this->travelTo(Carbon::parse('2026-01-01 00:00:00', 'UTC'));
        $now = now();

        $this->makeTicket(1, 'paid');
        $this->makeTicket(2, 'held', $now->copy()->addMinutes(5));
        $this->makeTicket(3, 'held', $now->copy()->addMinutes(-1));
        $this->makeTicket(4, 'released');
        $this->makeTicket(5, 'expired');

        $this->assertSeatmapAvailable(3);
        $this->assertSeatStatuses([1 => 'sold', 2 => 'held', 3 => 'available', 4 => 'available', 5 => 'available']);

        $this->travelTo($now->copy()->addMinutes(10));

        $this->assertSeatmapAvailable(4);
        $this->assertSeatStatuses([1 => 'sold', 2 => 'available', 3 => 'available', 4 => 'available', 5 => 'available']);
    }

    public function test_seatmap_available_is_zero_for_buffer_and_closed(): void
    {
        foreach (['buffer', 'closed'] as $allocation) {
            MatchBlockAllocation::where('match_id', $this->match->id)->update(['allocation' => $allocation]);
            $this->assertSeatmapAvailable(0);
        }
    }

    public function test_seats_is_404_for_buffer_and_closed_blocks(): void
    {
        foreach (['buffer', 'closed'] as $allocation) {
            MatchBlockAllocation::where('match_id', $this->match->id)->update(['allocation' => $allocation]);
            $this->getJson("/api/matches/{$this->match->id}/blocks/{$this->block->id}/seats")->assertStatus(404);
        }
    }

    public function test_held_ticket_is_not_consumed_at_exact_expiry(): void
    {
        $expiresAt = Carbon::parse('2026-01-01 00:10:00', 'UTC');
        $this->makeTicket(1, 'held', $expiresAt);

        $this->travelTo($expiresAt->copy()->subSecond());
        $this->assertSeatStatuses([1 => 'held', 2 => 'available', 3 => 'available', 4 => 'available', 5 => 'available']);

        $this->travelTo($expiresAt);
        $this->assertSeatmapAvailable(5);
        $this->assertSeatStatuses([1 => 'available', 2 => 'available', 3 => 'available', 4 => 'available', 5 => 'available']);
    }

    public function test_me_is_401_when_demo_flag_is_off(): void
    {
        $user = User::create(['name' => 'Taro', 'email' => 'f@example.com', 'password' => 'x']);
        config(['ticketing.demo_auth_enabled' => false]);

        $this->getJson('/api/me', ['X-Demo-User-Id' => (string) $user->id])->assertStatus(401);
        $this->getJson('/api/demo/users')->assertStatus(404);
    }

    public function test_demo_guard(): void
    {
        $user = User::create(['name' => 'Taro', 'email' => 'taro@example.com', 'password' => 'x',
            'gender' => 'male', 'birth_date' => '1990-04-01', 'prefecture' => 'Tokyo']);
        Membership::create(['user_id' => $user->id, 'kind' => 'season_pass',
            'valid_from' => '2026-01-01', 'valid_until' => '2026-12-31']);

        $this->getJson('/api/me')->assertStatus(401);
        $this->getJson('/api/me', ['X-Demo-User-Id' => '999999'])->assertStatus(401);

        $this->getJson('/api/me', ['X-Demo-User-Id' => (string) $user->id])
            ->assertOk()
            ->assertExactJson(['user' => [
                'id' => $user->id,
                'name' => 'Taro',
                'gender' => 'male',
                'birth_date' => '1990-04-01',
                'prefecture' => 'Tokyo',
                'role' => 'customer',
                'memberships' => ['season_pass'],
            ]]);
    }

    public function test_demo_users_supports_q_limit_and_memberships(): void
    {
        $a = User::create(['name' => 'Alice', 'email' => 'a@example.com', 'password' => 'x']);
        User::create(['name' => 'Bob', 'email' => 'b@example.com', 'password' => 'x']);
        User::create(['name' => 'Alicia', 'email' => 'c@example.com', 'password' => 'x']);
        Membership::create(['user_id' => $a->id, 'kind' => 'fan_club',
            'valid_from' => '2026-01-01', 'valid_until' => '2026-12-31']);

        $res = $this->getJson('/api/demo/users?q=ali')->assertOk();
        $this->assertSame(['Alice', 'Alicia'], array_column($res->json('data'), 'name'));
        $this->assertSame(['fan_club'], $res->json('data.0.memberships'));
        $this->assertSame([], $res->json('data.1.memberships'));

        $this->assertCount(1, $this->getJson('/api/demo/users?limit=1')->json('data'));
        $this->assertCount(3, $this->getJson('/api/demo/users')->json('data'));
    }

    public function test_demo_users_is_404_outside_local_and_testing(): void
    {
        $this->app['env'] = 'production';

        $this->getJson('/api/demo/users')->assertStatus(404);

        $user = User::create(['name' => 'Taro', 'email' => 't@example.com', 'password' => 'x']);
        $this->getJson('/api/me', ['X-Demo-User-Id' => (string) $user->id])->assertStatus(401);
    }

    public function test_match_prices_shape_and_order(): void
    {
        $stadiumId = $this->block->seatCategory->stadium_id;
        $cat2 = SeatCategory::create(['stadium_id' => $stadiumId, 'name' => 'A席', 'display_order' => 2]);
        $adult = TicketType::create(['name' => '大人', 'code' => 'adult', 'display_order' => 1]);
        $child = TicketType::create(['name' => '子供', 'code' => 'child', 'display_order' => 2]);

        foreach ([[$cat2->id, $child->id, 2000], [$cat2->id, $adult->id, 3000],
            [$this->block->seat_category_id, $child->id, 4000], [$this->block->seat_category_id, $adult->id, 5000]] as [$c, $t, $p]) {
            MatchPrice::create(['match_id' => $this->match->id, 'seat_category_id' => $c, 'ticket_type_id' => $t, 'price' => $p]);
        }

        $res = $this->getJson("/api/matches/{$this->match->id}/prices")->assertOk();

        $this->assertSame(
            [['S席', 'adult', 5000], ['S席', 'child', 4000], ['A席', 'adult', 3000], ['A席', 'child', 2000]],
            array_map(fn ($r) => [$r['seat_category'], $r['ticket_type']['code'], $r['price']], $res->json('data')),
        );
        $res->assertJsonPath('data.0.ticket_type', ['id' => $adult->id, 'code' => 'adult', 'name' => '大人']);
    }

    public function test_released_seat_can_be_held_again_but_active_duplicates_are_rejected(): void
    {
        $this->makeTicket(1, 'released');
        $this->makeTicket(1, 'held', now()->addMinutes(10));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->makeTicket(1, 'held', now()->addMinutes(10));
    }

    private function makeTicket(int $seatNo, string $status, ?Carbon $expiresAt = null): Ticket
    {
        return Ticket::create([
            'match_id' => $this->match->id,
            'block_id' => $this->block->id,
            'seat_id' => $this->seats[$seatNo]->id,
            'status' => $status,
            'qr_uuid' => (string) Str::uuid(),
            'expires_at' => $expiresAt,
        ]);
    }

    private function assertSeatmapAvailable(int $expected): void
    {
        $blocks = $this->getJson("/api/matches/{$this->match->id}/seatmap")->assertOk()->json('blocks');
        $this->assertSame($expected, $blocks[0]['available']);
    }

    /** @param array<int, string> $expected seat number => status */
    private function assertSeatStatuses(array $expected): void
    {
        $rows = $this->getJson("/api/matches/{$this->match->id}/blocks/{$this->block->id}/seats")
            ->assertOk()->json('rows');
        $actual = [];
        foreach ($rows[0]['seats'] as $s) {
            $actual[$s['number']] = $s['status'];
        }
        $this->assertSame($expected, $actual);
    }
}
