<?php

namespace Tests\Feature;

use App\Models\ActivityEvent;
use App\Models\Block;
use App\Models\FootballMatch;
use App\Models\MatchBlockAllocation;
use App\Models\MatchPrice;
use App\Models\Order;
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
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CartTest extends TestCase
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
        $this->getJson($this->url())->assertStatus(401);
        $this->postJson($this->url('/items'), ['seat_id' => $this->seats[1]->id])->assertStatus(401);
    }

    public function test_hold_reserved_seat(): void
    {
        $res = $this->add($this->alice, ['seat_id' => $this->seats[1]->id], ['X-Session-Id' => '0b7a3c52-1d1e-4c2f-9a77-5f3e8d2b6a10', 'X-Device' => 'mobile'])
            ->assertCreated();

        $cart = Order::firstOrFail();
        $ticket = Ticket::firstOrFail();
        $expires = Carbon::parse('2026-06-01 00:10:00', 'UTC');

        $res->assertJsonPath('max_items', 6)
            ->assertJsonPath('cart.id', $cart->id)
            ->assertJsonPath('cart.match_id', $this->match->id)
            ->assertJsonPath('cart.status', 'pending')
            ->assertJsonPath('cart.ordered_at', null)
            ->assertJsonPath('cart.total', 5000)
            ->assertJsonPath('cart.items.0.ticket_id', $ticket->id)
            ->assertJsonPath('cart.items.0.block', ['id' => $this->reserved->id, 'code' => 'M1', 'stand' => 'Main'])
            ->assertJsonPath('cart.items.0.seating_type', 'reserved')
            ->assertJsonPath('cart.items.0.seat', ['id' => $this->seats[1]->id, 'row' => 'A', 'number' => 1])
            ->assertJsonPath('cart.items.0.seat_category', 'S席')
            ->assertJsonPath('cart.items.0.ticket_type', ['id' => $this->adult->id, 'code' => 'adult', 'name' => '大人'])
            ->assertJsonPath('cart.items.0.price', 5000);
        $this->assertSame('+09:00', substr($res->json('cart.expires_at'), -6));
        $this->assertTrue($expires->equalTo(Carbon::parse($res->json('cart.expires_at'))));
        $this->assertSame('+09:00', substr($res->json('server_time'), -6));

        $this->assertSame('held', $ticket->status->value);
        $this->assertTrue($cart->expires_at->equalTo($expires));
        $this->assertTrue($ticket->expires_at->equalTo($cart->expires_at));
        $this->assertSame(5000, $cart->total);

        $event = ActivityEvent::firstOrFail();
        $this->assertSame('add_to_cart', $event->event_type);
        $this->assertSame($this->alice->id, $event->user_id);
        $this->assertSame('0b7a3c52-1d1e-4c2f-9a77-5f3e8d2b6a10', $event->session_id);
        $this->assertSame('mobile', $event->device);
        $this->assertEquals([
            'match_id' => $this->match->id, 'block_code' => 'M1', 'quantity' => 1,
            'order_id' => $cart->id, 'seat_id' => $this->seats[1]->id,
        ], $event->payload);
    }

    public function test_holding_same_seat_again_is_idempotent(): void
    {
        $first = $this->add($this->alice, ['seat_id' => $this->seats[1]->id])->assertCreated();
        $second = $this->add($this->alice, ['seat_id' => $this->seats[1]->id])->assertOk();

        $this->assertSame($first->json('cart'), $second->json('cart'));
        $this->assertSame(1, Ticket::count());
        $this->assertSame(1, ActivityEvent::count());
    }

    public function test_seat_held_by_another_user_is_409(): void
    {
        $this->add($this->alice, ['seat_id' => $this->seats[1]->id])->assertCreated();

        $this->add($this->bob, ['seat_id' => $this->seats[1]->id])
            ->assertStatus(409)->assertJsonPath('code', 'seat_unavailable');
        $this->assertSame(1, Ticket::count());
    }

    public function test_expired_hold_of_another_user_is_taken_over(): void
    {
        $this->add($this->alice, ['seat_id' => $this->seats[1]->id])->assertCreated();
        $old = Ticket::firstOrFail();

        $this->travelTo(Carbon::parse('2026-06-01 00:10:00', 'UTC'));
        $this->add($this->bob, ['seat_id' => $this->seats[1]->id])->assertCreated();

        $this->assertSame('expired', $old->fresh()->status->value);
        $this->assertSame(2, Ticket::count());
        $this->assertSame(1, Ticket::where('status', 'held')->count());
    }

    public function test_unreserved_respects_capacity_and_ignores_expired_holds(): void
    {
        $this->add($this->alice, ['block_id' => $this->free->id])->assertCreated()
            ->assertJsonPath('cart.items.0.seat', null)
            ->assertJsonPath('cart.items.0.seating_type', 'unreserved');
        $this->add($this->bob, ['block_id' => $this->free->id])->assertCreated();

        $this->add($this->bob, ['block_id' => $this->free->id])
            ->assertStatus(409)->assertJsonPath('code', 'sold_out');
        $this->assertSame(2, Ticket::count());

        $this->travelTo(Carbon::parse('2026-06-01 00:10:00', 'UTC'));
        $res = $this->add($this->bob, ['block_id' => $this->free->id])->assertCreated();
        $this->assertCount(1, $res->json('cart.items'));
        $this->assertSame(
            ['block_code' => 'F1', 'seat_id' => null],
            ['block_code' => ActivityEvent::latest('id')->first()->payload['block_code'],
                'seat_id' => ActivityEvent::latest('id')->first()->payload['seat_id']],
        );
    }

    public function test_cart_limit(): void
    {
        foreach (range(1, 6) as $n) {
            $this->add($this->alice, ['seat_id' => $this->seats[$n]->id])->assertCreated();
        }

        $this->add($this->alice, ['seat_id' => $this->seats[7]->id])
            ->assertStatus(422)->assertJsonPath('code', 'cart_limit_reached');
        $this->assertSame(6, Ticket::count());
    }

    public function test_not_on_sale_cases(): void
    {
        $seat = ['seat_id' => $this->seats[1]->id];

        $this->add($this->alice, $seat + ['ticket_type_id' => $this->pass->id])
            ->assertStatus(422)->assertJsonPath('code', 'not_on_sale');
        $this->add($this->alice, ['block_id' => $this->reserved->id])
            ->assertStatus(422)->assertJsonPath('code', 'not_on_sale');
        $this->add($this->alice, ['seat_id' => $this->seats[1]->id, 'block_id' => $this->free->id])
            ->assertStatus(422);
        $this->add($this->alice, [])->assertStatus(422);

        foreach (['buffer', 'closed'] as $allocation) {
            MatchBlockAllocation::where('block_id', $this->reserved->id)->update(['allocation' => $allocation]);
            $this->add($this->alice, $seat)->assertStatus(422)->assertJsonPath('code', 'not_on_sale');
        }
        MatchBlockAllocation::where('block_id', $this->reserved->id)->delete();
        $this->add($this->alice, $seat)->assertStatus(422)->assertJsonPath('code', 'not_on_sale');

        MatchBlockAllocation::where('block_id', $this->free->id)->update(['allocation' => 'buffer']);
        $this->add($this->alice, ['block_id' => $this->free->id])->assertStatus(422)->assertJsonPath('code', 'not_on_sale');

        $this->match->update(['status' => 'upcoming']);
        MatchBlockAllocation::create(['match_id' => $this->match->id, 'block_id' => $this->reserved->id, 'allocation' => 'home']);
        $this->add($this->alice, $seat)->assertStatus(422)->assertJsonPath('code', 'not_on_sale');

        $this->assertSame(0, Ticket::count());
    }

    public function test_price_not_found(): void
    {
        MatchPrice::where('ticket_type_id', $this->child->id)->delete();

        $this->add($this->alice, ['seat_id' => $this->seats[1]->id, 'ticket_type_id' => $this->child->id])
            ->assertStatus(422)->assertJsonPath('code', 'price_not_found');
        $this->assertSame(0, Ticket::count());
    }

    public function test_patch_changes_ticket_type_and_price(): void
    {
        $res = $this->add($this->alice, ['seat_id' => $this->seats[1]->id])->assertCreated();
        $itemId = $res->json('cart.items.0.id');

        $this->patchJson($this->url("/items/{$itemId}"), ['ticket_type_id' => $this->child->id], $this->h($this->alice))
            ->assertOk()
            ->assertJsonPath('cart.items.0.price', 2500)
            ->assertJsonPath('cart.items.0.ticket_type.code', 'child')
            ->assertJsonPath('cart.total', 2500);

        $this->patchJson($this->url("/items/{$itemId}"), ['ticket_type_id' => $this->pass->id], $this->h($this->alice))
            ->assertStatus(422)->assertJsonPath('code', 'not_on_sale');

        $this->patchJson($this->url("/items/{$itemId}"), ['ticket_type_id' => $this->child->id], $this->h($this->bob))
            ->assertStatus(404);

        $this->match->update(['status' => 'upcoming']);
        $this->patchJson($this->url("/items/{$itemId}"), ['ticket_type_id' => $this->adult->id], $this->h($this->alice))
            ->assertStatus(422)->assertJsonPath('code', 'not_on_sale');
        $this->match->update(['status' => 'on_sale']);

        $this->travelTo(Carbon::parse('2026-06-01 00:10:00', 'UTC'));
        $this->patchJson($this->url("/items/{$itemId}"), ['ticket_type_id' => $this->adult->id], $this->h($this->alice))
            ->assertStatus(404);
        $this->assertSame('expired', Ticket::firstOrFail()->status->value);
    }

    public function test_delete_after_cart_expiry_is_404_and_expires_ticket(): void
    {
        $res = $this->add($this->alice, ['seat_id' => $this->seats[1]->id])->assertCreated();
        $itemId = $res->json('cart.items.0.id');

        $this->travelTo(Carbon::parse('2026-06-01 00:10:00', 'UTC'));
        $this->deleteJson($this->url("/items/{$itemId}"), [], $this->h($this->alice))->assertStatus(404);

        $this->assertSame('expired', Ticket::firstOrFail()->status->value);
        $this->assertSame('expired', Order::firstOrFail()->status->value);
        $this->assertSame(0, ActivityEvent::where('event_type', 'remove_from_cart')->count());
    }

    public function test_cart_mutations_are_rate_limited_per_user(): void
    {
        config(['ticketing.cart_rate_per_minute' => 2]);

        $this->add($this->alice, ['seat_id' => $this->seats[1]->id])->assertCreated();
        $this->add($this->alice, ['seat_id' => $this->seats[2]->id])->assertCreated();
        $this->add($this->alice, ['seat_id' => $this->seats[3]->id])->assertStatus(429);
        $this->add($this->bob, ['seat_id' => $this->seats[4]->id])->assertCreated();
    }

    public function test_cart_show_is_not_rate_limited(): void
    {
        config(['ticketing.cart_rate_per_minute' => 2]);

        foreach (range(1, 5) as $_) {
            $this->getJson($this->url(), $this->h($this->alice))->assertOk();
        }
    }

    public function test_invalid_session_and_device_headers_are_sanitized(): void
    {
        $this->add($this->alice, ['seat_id' => $this->seats[1]->id], ['X-Session-Id' => 'not-a-uuid', 'X-Device' => 'toaster'])
            ->assertCreated();

        $event = ActivityEvent::firstOrFail();
        $this->assertSame('unknown', $event->session_id);
        $this->assertNull($event->device);
    }

    public function test_delete_releases_ticket_and_records_event(): void
    {
        $this->add($this->alice, ['seat_id' => $this->seats[1]->id])->assertCreated();
        $res = $this->add($this->alice, ['seat_id' => $this->seats[2]->id])->assertCreated();
        $itemId = $res->json('cart.items.0.id');
        $this->assertSame(10000, $res->json('cart.total'));

        $this->deleteJson($this->url("/items/{$itemId}"), [], $this->h($this->bob))->assertStatus(404);

        $del = $this->deleteJson($this->url("/items/{$itemId}"), [], $this->h($this->alice))
            ->assertOk()
            ->assertJsonPath('cart.total', 5000);
        $this->assertCount(1, $del->json('cart.items'));

        $this->assertSame('released', Ticket::find($res->json('cart.items.0.ticket_id'))->status->value);
        $this->assertSame(2, DB::table('order_items')->count());
        $this->assertSame(5000, Order::firstOrFail()->total);

        $event = ActivityEvent::where('event_type', 'remove_from_cart')->firstOrFail();
        $this->assertSame($this->seats[1]->id, $event->payload['seat_id']);
        $this->assertSame('M1', $event->payload['block_code']);

        // 解放した席は別ユーザーが押さえられる
        $this->add($this->bob, ['seat_id' => $this->seats[1]->id])->assertCreated();
    }

    public function test_cart_expiry_on_get_and_new_cart_on_post(): void
    {
        $this->add($this->alice, ['seat_id' => $this->seats[1]->id])->assertCreated();
        $oldOrder = Order::firstOrFail();

        $this->getJson($this->url(), $this->h($this->alice))->assertOk()->assertJsonPath('cart', fn ($v) => $v !== null);

        $this->travelTo(Carbon::parse('2026-06-01 00:10:00', 'UTC'));
        $this->getJson($this->url(), $this->h($this->alice))
            ->assertOk()->assertJsonPath('cart', null)->assertJsonPath('max_items', 6);

        $this->assertSame('expired', $oldOrder->fresh()->status->value);
        $this->assertSame('expired', Ticket::firstOrFail()->status->value);

        $res = $this->add($this->alice, ['seat_id' => $this->seats[1]->id])->assertCreated();
        $this->assertNotSame($oldOrder->id, $res->json('cart.id'));
        $this->assertSame(1, Order::where('status', 'pending')->count());
        $this->assertCount(1, $res->json('cart.items'));
    }

    public function test_expiry_is_reset_only_when_cart_has_no_live_held(): void
    {
        $first = $this->add($this->alice, ['seat_id' => $this->seats[1]->id])->assertCreated();
        $orderId = $first->json('cart.id');

        $this->travelTo(Carbon::parse('2026-06-01 00:05:00', 'UTC'));
        $this->add($this->alice, ['seat_id' => $this->seats[2]->id])->assertCreated();
        $this->assertTrue(Order::find($orderId)->expires_at->equalTo(Carbon::parse('2026-06-01 00:10:00', 'UTC')));
        $this->assertSame(2, Ticket::where('status', 'held')->where('expires_at', Carbon::parse('2026-06-01 00:10:00', 'UTC'))->count());

        $cart = $this->getJson($this->url(), $this->h($this->alice))->json('cart');
        foreach ($cart['items'] as $item) {
            $this->deleteJson($this->url("/items/{$item['id']}"), [], $this->h($this->alice))->assertOk();
        }

        $this->travelTo(Carbon::parse('2026-06-01 00:08:00', 'UTC'));
        $this->add($this->alice, ['seat_id' => $this->seats[3]->id])->assertCreated();
        $expected = Carbon::parse('2026-06-01 00:18:00', 'UTC');
        $this->assertTrue(Order::find($orderId)->expires_at->equalTo($expected));
        $this->assertTrue(Ticket::where('seat_id', $this->seats[3]->id)->firstOrFail()->expires_at->equalTo($expected));
    }

    public function test_expire_holds_command_only_expires_overdue_pending(): void
    {
        $this->add($this->alice, ['seat_id' => $this->seats[1]->id])->assertCreated();
        $this->travelTo(Carbon::parse('2026-06-01 00:05:00', 'UTC'));
        $this->add($this->bob, ['seat_id' => $this->seats[2]->id])->assertCreated();

        $this->travelTo(Carbon::parse('2026-06-01 00:11:00', 'UTC'));
        $this->artisan('tickets:expire-holds')->expectsOutput('expired 1 cart(s)')->assertSuccessful();

        $this->assertSame('expired', Order::where('user_id', $this->alice->id)->firstOrFail()->status->value);
        $this->assertSame('pending', Order::where('user_id', $this->bob->id)->firstOrFail()->status->value);
        $this->assertSame('expired', Ticket::where('seat_id', $this->seats[1]->id)->firstOrFail()->status->value);
        $this->assertSame('held', Ticket::where('seat_id', $this->seats[2]->id)->firstOrFail()->status->value);

        $this->artisan('tickets:expire-holds')->expectsOutput('expired 0 cart(s)')->assertSuccessful();
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

    private function url(string $suffix = ''): string
    {
        return "/api/matches/{$this->match->id}/cart{$suffix}";
    }

    private function h(User $user, array $extra = []): array
    {
        // 同一テスト内でユーザーを切り替えるため、guard のキャッシュを捨てる
        $this->app['auth']->forgetGuards();

        return ['X-Demo-User-Id' => (string) $user->id] + $extra;
    }

    private function add(User $user, array $body, array $headers = [])
    {
        return $this->postJson($this->url('/items'), $body, $this->h($user, $headers));
    }
}
