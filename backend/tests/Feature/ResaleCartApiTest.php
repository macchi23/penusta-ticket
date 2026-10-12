<?php

namespace Tests\Feature;

use App\Enums\OrderKind;
use App\Enums\ResaleHoldStatus;
use App\Enums\ResaleListingStatus;
use App\Models\ActivityEvent;
use App\Models\FootballMatch;
use App\Models\Order;
use App\Models\ResaleHold;
use App\Models\ResaleListing;
use App\Models\TicketType;
use App\Models\User;
use App\Services\Resale\ResaleService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsResaleTickets;
use Tests\Concerns\BuildsSaleFixture;
use Tests\TestCase;

class ResaleCartApiTest extends TestCase
{
    use BuildsResaleTickets;
    use BuildsSaleFixture;
    use RefreshDatabase;

    private FootballMatch $match;

    private TicketType $adult;

    private User $alice;

    private User $carol;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildResaleFixture();
        $this->carol = User::create(['name' => 'Carol', 'email' => 'c@example.com', 'password' => 'x']);
    }

    private function listed(User $seller, int $price = 4000): ResaleListing
    {
        return app(ResaleService::class)->list($seller, $this->paidTicket($seller), $price);
    }

    private function cartOf(?User $user)
    {
        $this->app['auth']->forgetGuards();

        return $this->getJson("/api/matches/{$this->match->id}/resale-cart", $user ? $this->h($user) : []);
    }

    private function hold(User $user, ResaleListing $listing)
    {
        return $this->postJson("/api/resale-listings/{$listing->id}/hold", [], $this->h($user));
    }

    private function release(User $user, int $holdId)
    {
        return $this->deleteJson("/api/resale-holds/{$holdId}", [], $this->h($user));
    }

    private function checkout(User $user, int $holdId)
    {
        return $this->postJson("/api/resale-holds/{$holdId}/checkout", [], $this->h($user));
    }

    /** 指定の SQL の実行後に時刻を進める（コントローラが自前で now を取ると、イベント時刻がサービスの now とずれる）。 */
    private function advanceAfter(string $sqlStart): void
    {
        DB::listen(function (QueryExecuted $q) use ($sqlStart) {
            if (str_starts_with($q->sql, $sqlStart)) {
                $this->travel(7)->seconds();
            }
        });
    }

    private function startedAt(): string
    {
        return '2030-02-01 00:00:00';
    }

    // GET

    public function test_cart_without_hold_is_null_with_server_time(): void
    {
        $res = $this->cartOf($this->bob)->assertOk();

        $this->assertNull($res->json('hold'));
        $this->assertSame('2030-02-01T09:00:00+09:00', $res->json('server_time'));
    }

    public function test_cart_returns_hold_shape(): void
    {
        $listing = $this->listed($this->alice, 4000);
        $id = $this->hold($this->bob, $listing)->assertCreated()->json('hold.id');

        $res = $this->cartOf($this->bob)->assertOk();

        $this->assertEquals([
            'id' => $id,
            'listing_id' => $listing->id,
            'match_id' => $this->match->id,
            'expires_at' => '2030-02-01T09:10:00+09:00',
            'block' => ['id' => $this->reserved->id, 'code' => 'M1', 'stand' => $this->reserved->stand->name],
            'seat' => ['id' => $listing->ticket->seat_id, 'row' => 'A', 'number' => $listing->ticket->seat->number],
            'seat_category' => $this->reserved->seatCategory->name,
            'ticket_type' => ['id' => $this->adult->id, 'code' => $this->adult->code, 'name' => $this->adult->name],
            'price' => 4000,
            'buyer_fee' => 220,
            'total' => 4220,
        ], $res->json('hold'));
        $content = $res->getContent();
        foreach (['ticket_id', 'qr_uuid', 'seller', 'payout', 'Alice'] as $needle) {
            $this->assertStringNotContainsString($needle, $content);
        }
        // 他人には見えない
        $this->assertNull($this->cartOf($this->carol)->json('hold'));
    }

    public function test_cart_ignores_expired_hold(): void
    {
        $this->hold($this->bob, $this->listed($this->alice))->assertCreated();
        $this->travel(11)->minutes();

        $this->assertNull($this->cartOf($this->bob)->assertOk()->json('hold'));
    }

    public function test_cart_requires_login(): void
    {
        $this->cartOf(null)->assertUnauthorized();
    }

    // hold

    public function test_hold_creates_then_is_idempotent(): void
    {
        $listing = $this->listed($this->alice, 4000);

        $first = $this->hold($this->bob, $listing)->assertCreated();
        $second = $this->hold($this->bob, $listing)->assertOk();

        $this->assertSame($first->json('hold.id'), $second->json('hold.id'));
        $this->assertSame(ResaleHoldStatus::Held, ResaleHold::sole()->status);
        $this->assertNotNull($first->json('server_time'));

        $event = ActivityEvent::where('event_type', 'resale_add_to_cart')->sole();
        $this->assertSame($this->bob->id, $event->user_id);
        $this->assertEquals([
            'listing_id' => $listing->id, 'hold_id' => $first->json('hold.id'), 'match_id' => $this->match->id,
            'block_id' => $this->reserved->id, 'price' => 4000, 'discount_rate' => 0.2, 'quantity' => 1,
        ], $event->payload);
    }

    public function test_hold_rejections(): void
    {
        $a = $this->listed($this->alice);
        $b = $this->listed($this->alice);

        $this->hold($this->alice, $a)->assertUnprocessable()->assertJsonPath('code', 'own_listing');

        $this->hold($this->bob, $a)->assertCreated();
        $this->hold($this->carol, $a)->assertStatus(409)->assertJsonPath('code', 'listing_unavailable');
        $this->hold($this->bob, $b)->assertStatus(409)->assertJsonPath('code', 'resale_hold_exists');

        $this->release($this->bob, ResaleHold::sole()->id)->assertNoContent();
        $this->hold($this->bob, $a)->assertStatus(409)->assertJsonPath('code', 'resale_hold_cooldown');

        $this->assertSame(1, ActivityEvent::where('event_type', 'resale_add_to_cart')->count());
    }

    public function test_hold_limit_and_closed(): void
    {
        $max = (int) config('ticketing.max_tickets_per_order');
        for ($i = 0; $i < $max; $i++) {
            app(ResaleService::class)->purchase($this->bob, $this->listed($this->alice));
        }
        $listing = $this->listed($this->alice);

        $this->hold($this->bob, $listing)->assertUnprocessable()->assertJsonPath('code', 'resale_limit_reached');

        $this->travelTo($this->match->kickoff_at->subMinutes(150));
        $this->hold($this->carol, $listing)->assertUnprocessable()->assertJsonPath('code', 'resale_closed');
        $this->assertSame(0, ResaleHold::count());
        $this->assertSame(0, ActivityEvent::where('event_type', 'resale_add_to_cart')->count());
    }

    public function test_hold_guest_and_bad_ids(): void
    {
        $listing = $this->listed($this->alice);

        $this->app['auth']->forgetGuards();
        $this->postJson("/api/resale-listings/{$listing->id}/hold")->assertUnauthorized();
        $this->postJson('/api/resale-listings/abc/hold', [], $this->h($this->bob))->assertNotFound();
        $this->postJson('/api/resale-listings/999999/hold', [], $this->h($this->bob))->assertNotFound()
            ->assertJsonPath('code', 'not_found');
    }

    public function test_hold_event_uses_service_time(): void
    {
        $listing = $this->listed($this->alice);
        $this->advanceAfter('insert into "resale_holds"');

        $res = $this->hold($this->bob, $listing)->assertCreated();

        $event = ActivityEvent::where('event_type', 'resale_add_to_cart')->sole();
        $this->assertSame($this->startedAt(), $event->occurred_at->utc()->toDateTimeString());
        $this->assertSame('2030-02-01T09:00:00+09:00', $res->json('server_time'));
    }

    // release

    public function test_release_records_event_once(): void
    {
        $listing = $this->listed($this->alice);
        $id = $this->hold($this->bob, $listing)->json('hold.id');

        $this->release($this->bob, $id)->assertNoContent();
        $this->release($this->bob, $id)->assertNoContent();

        $this->assertSame(ResaleHoldStatus::Released, ResaleHold::sole()->status);
        $event = ActivityEvent::where('event_type', 'resale_remove_from_cart')->sole();
        $this->assertEquals([
            'listing_id' => $listing->id, 'hold_id' => $id, 'match_id' => $this->match->id,
            'block_id' => $this->reserved->id, 'quantity' => 1,
        ], $event->payload);
        $this->assertNull($this->cartOf($this->bob)->json('hold'));
    }

    public function test_release_event_uses_service_time(): void
    {
        $id = $this->hold($this->bob, $this->listed($this->alice))->json('hold.id');
        $this->advanceAfter('update "resale_holds"');

        $this->release($this->bob, $id)->assertNoContent();

        $event = ActivityEvent::where('event_type', 'resale_remove_from_cart')->sole();
        $this->assertSame($this->startedAt(), $event->occurred_at->utc()->toDateTimeString());
    }

    public function test_release_of_others_or_missing_hold_is_404_without_model_name(): void
    {
        $id = $this->hold($this->bob, $this->listed($this->alice))->json('hold.id');

        $res = $this->release($this->carol, $id)->assertNotFound();
        $this->assertStringNotContainsString('ResaleHold', $res->getContent());
        $this->assertStringNotContainsString('App\\', $res->getContent());
        $this->assertSame('not_found', $res->json('code'));
        $this->release($this->carol, 999999)->assertNotFound();
        $this->deleteJson('/api/resale-holds/abc', [], $this->h($this->bob))->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->deleteJson("/api/resale-holds/{$id}")->assertUnauthorized();

        $this->assertSame(ResaleHoldStatus::Held, ResaleHold::sole()->status);
        $this->assertSame(0, ActivityEvent::where('event_type', 'resale_remove_from_cart')->count());
    }

    public function test_release_of_expired_hold_is_204_without_event(): void
    {
        $id = $this->hold($this->bob, $this->listed($this->alice))->json('hold.id');
        $this->travel(11)->minutes();

        $this->release($this->bob, $id)->assertNoContent();

        $this->assertSame(0, ActivityEvent::where('event_type', 'resale_remove_from_cart')->count());
    }

    // checkout

    public function test_checkout_creates_order_then_is_idempotent(): void
    {
        $listing = $this->listed($this->alice, 4000);
        $id = $this->hold($this->bob, $listing)->json('hold.id');

        $res = $this->checkout($this->bob, $id)->assertCreated();

        $order = $res->json('order');
        $this->assertSame('resale', $order['kind']);
        $this->assertSame('paid', $order['status']);
        $this->assertSame(220, $order['buyer_fee']);
        $this->assertSame(4220, $order['total']);
        $this->assertCount(1, $order['items']);
        $this->assertSame(ResaleListingStatus::Sold, $listing->fresh()->status);
        $this->assertSame(ResaleHoldStatus::Purchased, ResaleHold::sole()->status);

        $event = ActivityEvent::where('event_type', 'resale_purchase')->sole();
        $this->assertSame($this->bob->id, $event->user_id);
        $this->assertEquals([
            'listing_id' => $listing->id, 'hold_id' => $id, 'order_id' => $order['id'], 'match_id' => $this->match->id,
            'block_id' => $this->reserved->id, 'price' => 4000, 'total' => 4220, 'discount_rate' => 0.2,
        ], $event->payload);

        $again = $this->checkout($this->bob, $id)->assertOk();
        $this->assertSame($order['id'], $again->json('order.id'));
        $this->assertSame(1, ActivityEvent::where('event_type', 'resale_purchase')->count());
        $this->assertSame(1, Order::where('kind', OrderKind::Resale->value)->count());
    }

    public function test_checkout_event_uses_service_time(): void
    {
        $id = $this->hold($this->bob, $this->listed($this->alice))->json('hold.id');
        $this->advanceAfter('insert into "orders"');

        $this->checkout($this->bob, $id)->assertCreated();

        $event = ActivityEvent::where('event_type', 'resale_purchase')->sole();
        $this->assertSame($this->startedAt(), $event->occurred_at->utc()->toDateTimeString());
    }

    public function test_checkout_of_expired_hold_is_cart_expired(): void
    {
        $listing = $this->listed($this->alice);
        $id = $this->hold($this->bob, $listing)->json('hold.id');
        $this->travel(11)->minutes();

        $this->checkout($this->bob, $id)->assertStatus(409)->assertJsonPath('code', 'cart_expired');

        $this->assertSame(ResaleListingStatus::Active, $listing->fresh()->status);
        $this->assertSame(0, ActivityEvent::where('event_type', 'resale_purchase')->count());
    }

    public function test_checkout_of_others_hold_and_guest(): void
    {
        $id = $this->hold($this->bob, $this->listed($this->alice))->json('hold.id');

        $res = $this->checkout($this->carol, $id)->assertNotFound();
        $this->assertStringNotContainsString('ResaleHold', $res->getContent());
        $this->checkout($this->carol, 999999)->assertNotFound();
        $this->postJson('/api/resale-holds/abc/checkout', [], $this->h($this->bob))->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->postJson("/api/resale-holds/{$id}/checkout")->assertUnauthorized();
        $this->assertSame(0, Order::where('kind', OrderKind::Resale->value)->count());
    }

    public function test_checkout_payment_failure_records_event_and_keeps_hold(): void
    {
        $listing = $this->listed($this->alice);
        $id = $this->hold($this->bob, $listing)->json('hold.id');
        config(['ticketing.mock_payment_outcome' => 'declined']);

        $this->checkout($this->bob, $id)->assertStatus(402)->assertJsonPath('code', 'payment_failed');

        $this->assertSame(ResaleHoldStatus::Held, ResaleHold::sole()->status);
        $this->assertSame(ResaleListingStatus::Active, $listing->fresh()->status);
        $this->assertSame(0, Order::where('kind', OrderKind::Resale->value)->count());
        $event = ActivityEvent::where('event_type', 'payment_failed')->sole();
        $this->assertEquals([
            'source' => 'resale', 'match_id' => $this->match->id, 'listing_id' => $listing->id, 'hold_id' => $id,
            'quantity' => 1, 'reason' => 'declined',
        ], $event->payload);
        $this->assertSame(0, ActivityEvent::where('event_type', 'resale_purchase')->count());
    }

    // 出品者・一覧

    public function test_seller_sees_on_hold_and_cannot_cancel(): void
    {
        $listing = $this->listed($this->alice);
        $ticketId = $listing->ticket_id;
        $row = fn () => $this->getJson("/api/tickets/{$ticketId}", $this->h($this->alice))->assertOk()->json('ticket');

        $this->assertFalse($row()['listing']['on_hold']);
        $this->assertTrue($row()['can_cancel']);

        $holdId = $this->hold($this->bob, $listing)->json('hold.id');
        $held = $row();
        $this->assertTrue($held['listing']['on_hold']);
        $this->assertFalse($held['can_cancel']);
        $list = $this->getJson('/api/me/tickets', $this->h($this->alice))->assertOk()->json('data');
        $this->assertTrue($list[0]['listing']['on_hold']);
        $this->assertFalse($list[0]['can_cancel']);

        $this->deleteJson("/api/tickets/{$ticketId}/listing", [], $this->h($this->alice))
            ->assertStatus(409)->assertJsonPath('code', 'listing_held');

        $this->release($this->bob, $holdId)->assertNoContent();
        $after = $row();
        $this->assertFalse($after['listing']['on_hold']);
        $this->assertTrue($after['can_cancel']);
    }

    public function test_held_listing_is_hidden_from_board_for_others_and_guests(): void
    {
        $listing = $this->listed($this->alice);
        $other = $this->listed($this->alice);
        $this->hold($this->bob, $listing)->assertCreated();

        foreach ([null, $this->carol, $this->bob] as $viewer) {
            $this->app['auth']->forgetGuards();
            $res = $this->getJson("/api/matches/{$this->match->id}/resale-listings", $viewer ? $this->h($viewer) : []);
            $ids = collect($res->json('blocks'))->flatMap(fn ($b) => array_column($b['listings'], 'id'))->all();
            $this->assertNotContains($listing->id, $ids);
            if ($viewer !== null) {
                $this->assertSame([$other->id], $ids);
            }
        }
    }

    public function test_not_found_message_does_not_leak_model_name(): void
    {
        $res = $this->getJson('/api/orders/999999', $this->h($this->bob))->assertNotFound();

        $this->assertStringNotContainsString('App\\', $res->getContent());
    }
}
