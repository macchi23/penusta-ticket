<?php

namespace Tests\Feature;

use App\Models\ActivityEvent;
use App\Models\Block;
use App\Models\FootballMatch;
use App\Models\MatchBlockAllocation;
use App\Models\MatchPrice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Season;
use App\Models\Seat;
use App\Models\SeatCategory;
use App\Models\Stadium;
use App\Models\Stand;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use RefreshDatabase;

    private FootballMatch $match;

    private Block $reserved;

    private Block $free;

    private TicketType $adult;

    private TicketType $child;

    private TicketType $pass;

    private User $alice;

    private User $bob;

    /** @var array<int, Seat> */
    private array $seats = [];

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

        $this->reserved = $this->makeBlock($stand, $category, 'M1', 'reserved', 10, 'home');
        $this->free = $this->makeBlock($stand, $category, 'F1', 'unreserved', 2, 'away');

        foreach (range(1, 8) as $n) {
            $this->seats[$n] = Seat::create(['block_id' => $this->reserved->id, 'row_label' => 'A', 'number' => $n]);
        }

        $this->adult = TicketType::create(['name' => '大人', 'code' => 'adult', 'display_order' => 1]);
        $this->child = TicketType::create(['name' => '子供', 'code' => 'child', 'display_order' => 2]);
        $this->pass = TicketType::create(['name' => '年パス', 'code' => 'season_pass', 'display_order' => 3]);
        foreach ([[$this->adult, 5000], [$this->child, 2500], [$this->pass, 0]] as [$type, $price]) {
            MatchPrice::create(['match_id' => $this->match->id, 'seat_category_id' => $category->id,
                'ticket_type_id' => $type->id, 'price' => $price]);
        }

        $this->alice = User::create(['name' => 'Alice', 'email' => 'a@example.com', 'password' => 'x']);
        $this->bob = User::create(['name' => 'Bob', 'email' => 'b@example.com', 'password' => 'x']);
    }

    public function test_unauthenticated_is_401(): void
    {
        $order = $this->cartWithThree();
        $this->app['auth']->forgetGuards();
        $this->postJson("/api/orders/{$order->id}/checkout")->assertStatus(401);
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/orders/{$order->id}")->assertStatus(401);
    }

    public function test_checkout_confirms_cart(): void
    {
        $order = $this->cartWithThree();
        $this->travel(3)->minutes();

        $res = $this->checkout($this->alice, $order, ['X-Session-Id' => '0b7a3c52-1d1e-4c2f-9a77-5f3e8d2b6a10'])
            ->assertOk()
            ->assertJsonPath('order.id', $order->id)
            ->assertJsonPath('order.status', 'paid')
            ->assertJsonPath('order.expires_at', null)
            ->assertJsonPath('order.total', 12500)
            ->assertJsonCount(3, 'order.items');
        $this->assertTrue(Carbon::now()->equalTo(Carbon::parse($res->json('order.ordered_at'))));

        $this->assertSame(3, Ticket::where('status', 'paid')->whereNull('expires_at')->count());
        $this->assertSame(3, Ticket::count());
        $event = ActivityEvent::where('event_type', 'purchase')->sole();
        $this->assertSame(['match_id' => $this->match->id, 'order_id' => $order->id, 'quantity' => 3], $event->payload);
    }

    public function test_prices_are_recalculated(): void
    {
        $order = $this->cartWithThree();
        MatchPrice::where('ticket_type_id', $this->adult->id)->update(['price' => 6000]);

        $this->checkout($this->alice, $order)->assertOk()->assertJsonPath('order.total', 14500);

        $this->assertSame([6000, 6000, 2500], OrderItem::orderBy('id')->pluck('price')->all());
        $this->assertSame(14500, $order->fresh()->total);
    }

    public function test_checkout_is_idempotent(): void
    {
        $order = $this->cartWithThree();
        $first = $this->checkout($this->alice, $order)->assertOk()->json();
        $this->travel(1)->minutes();
        $second = $this->checkout($this->alice, $order)->assertOk()->json();

        $this->assertSame($first, $second);
        $this->assertSame(3, Ticket::count());
        $this->assertSame(1, ActivityEvent::where('event_type', 'purchase')->count());
    }

    public function test_expired_cart_is_409_and_reclaimed(): void
    {
        $order = $this->cartWithThree();
        $this->travel(11)->minutes();

        $this->checkout($this->alice, $order)->assertStatus(409)->assertJsonPath('code', 'cart_expired');

        $this->assertSame('expired', $order->fresh()->status->value);
        $this->assertSame(3, Ticket::where('status', 'expired')->count());
        $this->checkout($this->alice, $order)->assertStatus(409)->assertJsonPath('code', 'cart_expired');
    }

    public function test_partially_expired_cart_is_not_confirmed(): void
    {
        $order = $this->cartWithThree();
        Ticket::orderBy('id')->firstOrFail()->update(['status' => 'expired']);

        $this->checkout($this->alice, $order)->assertStatus(409)->assertJsonPath('code', 'cart_expired');

        $this->assertSame('expired', $order->fresh()->status->value);
        $this->assertSame(3, Ticket::where('status', 'expired')->count());
        $this->assertSame(0, ActivityEvent::where('event_type', 'purchase')->count());
    }

    public function test_empty_cart_is_422(): void
    {
        $order = $this->cartWithThree();
        foreach (OrderItem::pluck('id') as $id) {
            $this->deleteJson("/api/matches/{$this->match->id}/cart/items/{$id}", [], $this->h($this->alice))->assertOk();
        }

        $this->checkout($this->alice, $order)->assertStatus(422)->assertJsonPath('code', 'cart_empty');
    }

    public function test_not_on_sale_keeps_pending(): void
    {
        $order = $this->cartWithThree();
        $this->match->update(['status' => 'scheduled']);

        $this->checkout($this->alice, $order)->assertStatus(422)->assertJsonPath('code', 'not_on_sale');

        $this->assertSame('pending', $order->fresh()->status->value);
        $this->assertSame(3, Ticket::where('status', 'held')->count());
    }

    public function test_declined_keeps_pending_and_records_event(): void
    {
        $order = $this->cartWithThree();
        MatchPrice::where('ticket_type_id', $this->adult->id)->update(['price' => 6000]);
        config(['ticketing.mock_payment_outcome' => 'declined']);

        $this->checkout($this->alice, $order)->assertStatus(402)->assertJsonPath('code', 'payment_declined');

        $this->assertSame('pending', $order->fresh()->status->value);
        $this->assertSame(3, Ticket::where('status', 'held')->count());
        $this->assertSame(5000, (int) OrderItem::orderBy('id')->value('price'));
        $this->assertSame(0, ActivityEvent::where('event_type', 'purchase')->count());
        $event = ActivityEvent::where('event_type', 'payment_failed')->sole();
        $this->assertEquals(
            ['match_id' => $this->match->id, 'order_id' => $order->id, 'quantity' => 3, 'reason' => 'declined'],
            $event->payload,
        );
    }

    public function test_other_users_order_is_404(): void
    {
        $order = $this->cartWithThree();

        $this->checkout($this->bob, $order)->assertNotFound();
        $this->getJson("/api/orders/{$order->id}", $this->h($this->bob))->assertNotFound();
        $this->assertSame('pending', $order->fresh()->status->value);
    }

    public function test_show_returns_pending_and_paid(): void
    {
        $order = $this->cartWithThree();

        $this->getJson("/api/orders/{$order->id}", $this->h($this->alice))
            ->assertOk()
            ->assertJsonPath('order.status', 'pending')
            ->assertJsonPath('order.ordered_at', null)
            ->assertJsonPath('order.total', 12500)
            ->assertJsonPath('order.items.0.seat_category', 'S席');

        $this->checkout($this->alice, $order)->assertOk();

        $this->getJson("/api/orders/{$order->id}", $this->h($this->alice))
            ->assertOk()
            ->assertJsonPath('order.status', 'paid')
            ->assertJsonPath('order.expires_at', null)
            ->assertJsonCount(3, 'order.items');
    }

    private function cartWithThree(): Order
    {
        foreach ([1, 2] as $n) {
            $this->postJson("/api/matches/{$this->match->id}/cart/items", ['seat_id' => $this->seats[$n]->id], $this->h($this->alice))->assertCreated();
        }
        $this->postJson("/api/matches/{$this->match->id}/cart/items", ['block_id' => $this->free->id, 'ticket_type_id' => $this->child->id], $this->h($this->alice))->assertCreated();

        return Order::firstOrFail();
    }

    private function checkout(User $user, Order $order, array $headers = [])
    {
        return $this->postJson("/api/orders/{$order->id}/checkout", [], $this->h($user, $headers));
    }

    private function makeBlock(Stand $stand, SeatCategory $category, string $code, string $type, int $capacity, string $allocation): Block
    {
        $block = Block::create([
            'stand_id' => $stand->id, 'seat_category_id' => $category->id, 'code' => $code,
            'seating_type' => $type, 'capacity' => $capacity, 'geometry' => ['type' => 'rect'],
        ]);
        MatchBlockAllocation::create(['match_id' => $this->match->id, 'block_id' => $block->id, 'allocation' => $allocation]);

        return $block;
    }

    private function h(User $user, array $extra = []): array
    {
        // 同一テスト内でユーザーを切り替えるため、guard のキャッシュを捨てる
        $this->app['auth']->forgetGuards();

        return ['X-Demo-User-Id' => (string) $user->id] + $extra;
    }
}
