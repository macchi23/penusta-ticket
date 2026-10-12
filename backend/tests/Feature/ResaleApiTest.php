<?php

namespace Tests\Feature;

use App\Enums\OrderKind;
use App\Enums\ResaleListingStatus;
use App\Enums\TicketStatus;
use App\Models\ActivityEvent;
use App\Models\FootballMatch;
use App\Models\Order;
use App\Models\ResaleListing;
use App\Models\SalePhase;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Services\Resale\ResaleService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsResaleTickets;
use Tests\Concerns\BuildsSaleFixture;
use Tests\TestCase;

class ResaleApiTest extends TestCase
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

    private function list(User $user, Ticket $ticket, mixed $price)
    {
        return $this->postJson("/api/tickets/{$ticket->id}/listing", ['price' => $price], $this->h($user));
    }

    private function listed(User $seller, int $price = 4000, $block = null): ResaleListing
    {
        return app(ResaleService::class)->list($seller, $this->paidTicket($seller, $block), $price);
    }

    private function board(?User $user = null)
    {
        $this->app['auth']->forgetGuards();

        return $this->getJson("/api/matches/{$this->match->id}/resale-listings", $user ? $this->h($user) : []);
    }

    private function buy(User $user, ResaleListing $listing)
    {
        return $this->postJson("/api/resale-listings/{$listing->id}/purchase", [], $this->h($user));
    }

    // 出品・取り下げ

    public function test_list_creates_listing_and_records_event(): void
    {
        $ticket = $this->paidTicket($this->alice);

        $res = $this->list($this->alice, $ticket, 4000)->assertCreated();

        $this->assertSame('active', $res->json('listing.status'));
        $this->assertSame(4000, $res->json('listing.price'));
        $this->assertSame(400, $res->json('listing.seller_fee'));
        $this->assertSame(330, $res->json('listing.transfer_fee'));
        $this->assertSame(3270, $res->json('listing.payout'));
        $this->assertArrayNotHasKey('ticket_id', $res->json('listing'));
        $event = ActivityEvent::where('event_type', 'resale_list')->sole();
        $this->assertSame($this->alice->id, $event->user_id);
        $this->assertEquals([
            'listing_id' => $res->json('listing.id'), 'match_id' => $this->match->id, 'block_id' => $this->reserved->id,
            'price' => 4000, 'source' => 'purchase',
        ], $event->payload);
    }

    public function test_list_season_pass_ticket_records_source(): void
    {
        $ticket = $this->passTicket($this->alice);

        $this->list($this->alice, $ticket, 3000)->assertCreated();
        $this->list($this->bob, $this->passTicket($this->bob), 3751)->assertUnprocessable()->assertJsonPath('code', 'price_out_of_range');

        $this->assertSame('season_pass', ActivityEvent::where('event_type', 'resale_list')->sole()->payload['source']);
    }

    public function test_list_price_validation(): void
    {
        $ticket = $this->paidTicket($this->alice);

        $this->list($this->alice, $ticket, 5001)->assertUnprocessable()->assertJsonPath('code', 'price_out_of_range');
        $this->list($this->alice, $ticket, 499)->assertUnprocessable()->assertJsonPath('code', 'price_out_of_range');
        $this->list($this->alice, $ticket, null)->assertUnprocessable()->assertJsonValidationErrors('price');
        $this->list($this->alice, $ticket, 'abc')->assertUnprocessable()->assertJsonValidationErrors('price');
        $this->assertSame(0, ResaleListing::count());
        $this->assertSame(0, ActivityEvent::where('event_type', 'resale_list')->count());
        $this->list($this->alice, $ticket, 5000)->assertCreated();
    }

    public function test_list_others_ticket_is_404_and_guest_is_401(): void
    {
        $ticket = $this->paidTicket($this->alice);

        $this->list($this->bob, $ticket, 4000)->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->postJson("/api/tickets/{$ticket->id}/listing", ['price' => 4000])->assertUnauthorized();
        $this->assertSame(0, ResaleListing::count());
    }

    public function test_list_twice_and_resale_ticket_and_closed_window(): void
    {
        $ticket = $this->paidTicket($this->alice);
        $this->list($this->alice, $ticket, 4000)->assertCreated();
        $this->list($this->alice, $ticket, 3000)->assertUnprocessable()->assertJsonPath('code', 'not_listable');

        $bought = app(ResaleService::class)->purchase($this->bob, $this->listed($this->carol));
        $this->list($this->bob, $bought->newTicket, 3000)->assertUnprocessable()->assertJsonPath('code', 'not_listable');

        $another = $this->paidTicket($this->alice);
        $this->travelTo($this->match->kickoff_at->subMinutes(150));
        $this->list($this->alice, $another, 3000)->assertUnprocessable()->assertJsonPath('code', 'resale_closed');
    }

    public function test_cancel_listing(): void
    {
        $listing = $this->listed($this->alice);

        $res = $this->deleteJson("/api/tickets/{$listing->ticket_id}/listing", [], $this->h($this->alice))->assertOk();

        $this->assertSame('cancelled', $res->json('listing.status'));
        $this->assertSame(ResaleListingStatus::Cancelled, $listing->fresh()->status);
        $event = ActivityEvent::where('event_type', 'resale_cancel')->sole();
        $this->assertEquals(['listing_id' => $listing->id, 'match_id' => $this->match->id], $event->payload);

        // 取り下げ済みの再取り下げ
        $this->deleteJson("/api/tickets/{$listing->ticket_id}/listing", [], $this->h($this->alice))
            ->assertStatus(409)->assertJsonPath('code', 'listing_unavailable');
    }

    public function test_cancel_is_allowed_after_deadline_and_when_not_on_sale(): void
    {
        $listing = $this->listed($this->alice);
        $ticket = $listing->ticket;
        $this->travelTo($this->match->kickoff_at->subMinutes(150));

        $row = fn () => $this->getJson("/api/tickets/{$ticket->id}", $this->h($this->alice))->assertOk()->json('ticket');
        $this->assertTrue($row()['can_cancel']);
        $this->assertFalse($row()['can_list']);
        $this->assertNull($row()['qr_uuid']);

        $this->deleteJson("/api/tickets/{$ticket->id}/listing", [], $this->h($this->alice))->assertOk()
            ->assertJsonPath('listing.status', 'cancelled');
        $after = $row();
        $this->assertFalse($after['can_cancel']);
        $this->assertSame($ticket->qr_uuid, $after['qr_uuid']);

        // on_sale 以外になった試合でも取り下げられる
        $this->travelTo(CarbonImmutable::parse('2030-02-01 00:00:00', 'UTC'));
        $second = $this->listed($this->alice);
        $this->match->update(['status' => 'scheduled']);
        $this->assertTrue($this->getJson("/api/tickets/{$second->ticket_id}", $this->h($this->alice))->json('ticket.can_cancel'));
        $this->deleteJson("/api/tickets/{$second->ticket_id}/listing", [], $this->h($this->alice))->assertOk();
    }

    public function test_cancel_others_listing_is_404_and_guest_401(): void
    {
        $listing = $this->listed($this->alice);

        $this->deleteJson("/api/tickets/{$listing->ticket_id}/listing", [], $this->h($this->bob))->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->deleteJson("/api/tickets/{$listing->ticket_id}/listing")->assertUnauthorized();
        $this->assertSame(ResaleListingStatus::Active, $listing->fresh()->status);
    }

    // 一覧

    public function test_board_status_variants(): void
    {
        $this->board()->assertOk()->assertJsonPath('status', 'login_required')->assertJsonPath('blocks', []);

        $open = $this->board($this->bob)->assertOk();
        $open->assertJsonPath('status', 'open');
        $this->assertNotNull($open->json('opens_at'));
        $this->assertNotNull($open->json('closes_at'));

        $this->makeSalePhases('presale');
        $this->board($this->bob)->assertJsonPath('status', 'not_started');
        $this->makeSalePhases('before');
        $this->board($this->bob)->assertJsonPath('status', 'not_started');

        SalePhase::where('match_id', $this->match->id)->where('kind', 'general')->delete();
        $none = $this->board($this->bob)->assertJsonPath('status', 'not_started');
        $this->assertNull($none->json('opens_at'));

        $this->makeSalePhases('general');
        $this->travelTo($this->match->kickoff_at->subMinutes(150));
        $this->board($this->bob)->assertJsonPath('status', 'closed')->assertJsonPath('blocks', []);

        $this->travelTo(CarbonImmutable::parse('2030-02-01 00:00:00', 'UTC'));
        $this->match->update(['status' => 'scheduled']);
        $this->board($this->bob)->assertJsonPath('status', 'closed');
    }

    public function test_board_hides_listings_unless_open(): void
    {
        $this->listed($this->alice);

        $this->assertSame([], $this->board()->json('blocks'));
        $this->makeSalePhases('presale');
        $this->assertSame([], $this->board($this->bob)->json('blocks'));
    }

    public function test_board_excludes_own_and_aggregates_by_block_sorted_by_total(): void
    {
        $this->listed($this->alice, 4000);
        $cheap = $this->listed($this->alice, 1000);
        $mid = $this->listed($this->carol, 2500);
        $freeOne = $this->listed($this->alice, 3000, $this->free);
        $mine = $this->listed($this->bob, 500);
        $cancelled = $this->listed($this->alice, 900);
        $cancelled->update(['status' => ResaleListingStatus::Cancelled]);

        $body = $this->board($this->bob)->assertOk()->json();

        $this->assertCount(2, $body['blocks']);
        [$reserved, $free] = $body['blocks'];
        $this->assertSame('M1', $reserved['block']['code']);
        $this->assertSame(3, $reserved['count']);
        $this->assertSame(1220, $reserved['min_total']);
        $this->assertSame([$cheap->id, $mid->id], array_slice(array_column($reserved['listings'], 'id'), 0, 2));
        $this->assertSame([1220, 2720, 4220], array_column($reserved['listings'], 'total'));
        $this->assertSame(220, $reserved['listings'][0]['buyer_fee']);
        $this->assertSame('A', $reserved['listings'][0]['seat']['row']);
        $this->assertSame('F1', $free['block']['code']);
        $this->assertNull($free['listings'][0]['seat']);
        $this->assertSame($freeOne->id, $free['listings'][0]['id']);
        $this->assertNotContains($mine->id, array_column($reserved['listings'], 'id'));
        $this->assertNotContains($cancelled->id, array_column($reserved['listings'], 'id'));
    }

    public function test_board_does_not_leak_seller_data(): void
    {
        $listing = $this->listed($this->alice, 4000);

        $res = $this->board($this->bob)->assertOk();
        $offer = $res->json('blocks.0.listings.0');

        $this->assertEqualsCanonicalizing(['id', 'seat', 'ticket_type', 'price', 'buyer_fee', 'total'], array_keys($offer));
        $content = $res->getContent();
        foreach (['seller', 'payout', 'seller_fee', 'ticket_id', 'qr_uuid', 'face_price', $listing->ticket->qr_uuid, 'Alice', 'a@example.com'] as $needle) {
            $this->assertStringNotContainsString($needle, $content);
        }
        $this->assertStringNotContainsString('"3270"', $content);
        $this->assertStringNotContainsString(':3270', $content);
    }

    public function test_board_skips_listings_past_deadline_not_yet_expired(): void
    {
        $this->listed($this->alice);
        $this->travelTo($this->match->kickoff_at->subMinutes(151));

        $this->assertCount(1, $this->board($this->bob)->json('blocks'));

        $this->travelTo($this->match->kickoff_at->subMinutes(150));
        $this->assertSame([], $this->board($this->bob)->json('blocks'));
    }

    // 購入

    public function test_purchase_creates_resale_order_and_event(): void
    {
        $listing = $this->listed($this->alice, 4000);

        $res = $this->buy($this->bob, $listing)->assertCreated();

        $order = $res->json('order');
        $this->assertSame('resale', $order['kind']);
        $this->assertSame('paid', $order['status']);
        $this->assertSame(220, $order['buyer_fee']);
        $this->assertSame(4220, $order['total']);
        $this->assertSame(4220, Order::find($order['id'])->total);
        $this->assertCount(1, $order['items']);
        $this->assertSame(4000, $order['items'][0]['price']);
        $this->assertSame('paid', $order['items'][0]['ticket_status']);
        $this->assertNull($order['items'][0]['resale_payout']);
        $this->assertSame(ResaleListingStatus::Sold, $listing->fresh()->status);

        $event = ActivityEvent::where('event_type', 'resale_purchase')->sole();
        $this->assertSame($this->bob->id, $event->user_id);
        $this->assertEquals([
            'listing_id' => $listing->id, 'order_id' => $order['id'], 'match_id' => $this->match->id,
            'block_id' => $this->reserved->id, 'price' => 4000, 'total' => 4220, 'discount_rate' => 0.2,
        ], $event->payload);
    }

    public function test_purchase_rejections(): void
    {
        $listing = $this->listed($this->alice, 4000);

        $this->buy($this->alice, $listing)->assertUnprocessable()->assertJsonPath('code', 'own_listing');
        $this->app['auth']->forgetGuards();
        $this->postJson("/api/resale-listings/{$listing->id}/purchase")->assertUnauthorized();
        $this->postJson('/api/resale-listings/999999/purchase', [], $this->h($this->bob))->assertNotFound();

        $this->buy($this->bob, $listing)->assertCreated();
        $this->buy($this->carol, $listing)->assertStatus(409)->assertJsonPath('code', 'listing_unavailable');
        $this->assertSame(1, ActivityEvent::where('event_type', 'resale_purchase')->count());
    }

    public function test_purchase_after_deadline_is_closed(): void
    {
        $listing = $this->listed($this->alice, 4000);
        $this->travelTo($this->match->kickoff_at->subMinutes(150));

        $this->buy($this->bob, $listing)->assertUnprocessable()->assertJsonPath('code', 'resale_closed');
        $this->assertSame(ResaleListingStatus::Active, $listing->fresh()->status);
    }

    public function test_purchase_limit(): void
    {
        $max = (int) config('ticketing.max_tickets_per_order');
        for ($i = 0; $i < $max; $i++) {
            $this->buy($this->bob, $this->listed($this->alice))->assertCreated();
        }

        $this->buy($this->bob, $this->listed($this->alice))->assertUnprocessable()->assertJsonPath('code', 'resale_limit_reached');
    }

    public function test_payment_failure_records_event_and_rolls_back(): void
    {
        $listing = $this->listed($this->alice, 4000);
        config(['ticketing.mock_payment_outcome' => 'declined']);

        $this->buy($this->bob, $listing)->assertStatus(402)->assertJsonPath('code', 'payment_failed');

        $this->assertSame(ResaleListingStatus::Active, $listing->fresh()->status);
        $this->assertSame(TicketStatus::Paid, $listing->ticket->fresh()->status);
        $this->assertSame(0, Order::where('kind', OrderKind::Resale->value)->count());
        $event = ActivityEvent::where('event_type', 'payment_failed')->sole();
        $this->assertEquals([
            'source' => 'resale', 'match_id' => $this->match->id, 'listing_id' => $listing->id, 'quantity' => 1, 'reason' => 'declined',
        ], $event->payload);
        $this->assertSame(0, ActivityEvent::where('event_type', 'resale_purchase')->count());
    }

    // OrderResource

    public function test_sellers_original_order_keeps_resold_item_and_total(): void
    {
        $listing = $this->listed($this->alice, 4000);
        $orderId = $listing->ticket->orderItems()->first()->order_id;
        $before = $this->getJson("/api/orders/{$orderId}", $this->h($this->alice))->assertOk()->json('order');
        $this->assertSame(5000, $before['total']);
        $this->assertSame('paid', $before['items'][0]['ticket_status']);
        $this->assertSame(0, $before['buyer_fee']);

        $this->buy($this->bob, $listing)->assertCreated();

        $after = $this->getJson("/api/orders/{$orderId}", $this->h($this->alice))->assertOk()->json('order');
        $this->assertSame('purchase', $after['kind']);
        $this->assertSame(5000, $after['total']);
        $this->assertSame(0, $after['buyer_fee']);
        $this->assertCount(1, $after['items']);
        $this->assertSame('resold', $after['items'][0]['ticket_status']);
        $this->assertSame($listing->ticket_id, $after['items'][0]['ticket_id']);
        $this->assertSame(3270, $after['items'][0]['resale_payout']);
    }

    public function test_existing_order_shape_is_unchanged_apart_from_new_keys(): void
    {
        $ticket = $this->paidTicket($this->alice);
        $orderId = $ticket->orderItems()->first()->order_id;

        $order = $this->getJson("/api/orders/{$orderId}", $this->h($this->alice))->assertOk()->json('order');

        $this->assertEqualsCanonicalizing(
            ['id', 'match_id', 'status', 'kind', 'expires_at', 'ordered_at', 'total', 'buyer_fee', 'items'],
            array_keys($order),
        );
        $this->assertEqualsCanonicalizing(
            ['id', 'ticket_id', 'block', 'seating_type', 'seat', 'seat_category', 'ticket_type', 'price', 'ticket_status', 'resale_payout'],
            array_keys($order['items'][0]),
        );
        $this->assertSame(5000, $order['total']);
    }

    // イベント

    public function test_view_resale_event_is_accepted(): void
    {
        $this->postJson('/api/events', ['type' => 'view_resale', 'match_id' => $this->match->id])->assertNoContent();

        $this->assertEquals(['match_id' => $this->match->id], ActivityEvent::where('event_type', 'view_resale')->sole()->payload);
    }
}
